package reception

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"log"
	"strings"
	"sync"
	"time"

	"github.com/cloudwego/eino/schema"
	"github.com/dunglas/frankenphp"

	aiintegration "helmdesk/internal/app/integration/ai"
	"helmdesk/internal/app/phpbridge"
)

// 接待 actor 的关键时间窗口。
const (
	// idleTimeout 决定空闲多久后 actor 自然退出；后续访客消息会重建新 actor。
	idleTimeout = 5 * time.Minute

	// turnPayloadPreviewLength 限制接待事件详情中的文本预览长度。
	turnPayloadPreviewLength = 240

	// turnTimeout 限制单次 Runner.Run 的最大时长，覆盖首 token + 多轮工具调用。
	turnTimeout = 20 * time.Second

	// maxIterations 限制单轮内 LLM ↔ tool 来回上限。
	maxIterations = 4

	// eventBufferSize 给事件 chan 足够裕度，支撑多 task 同时回流。
	eventBufferSize = 32

	// maxConcurrentTasksPerConversation 单会话同时活跃任务上限；超出时 dispatch_task 拒绝。
	maxConcurrentTasksPerConversation = 5

	// visitorDebounceWindow 是访客消息聚合的基础静默窗口：第一条消息进缓冲后，
	// 至少静默这么久才 flush 成一条 user message 喂给 LLM。
	// 客服场景下访客经常想一句发一句，没必要每发一条就触发一次 ReAct。
	visitorDebounceWindow = 1500 * time.Millisecond

	// visitorDebounceStep 是自适应窗口的每条增量：缓冲里每多堆积一条未 flush 的消息，
	// 就把静默窗口顺延这么久——访客正连发碎句时给更多耐心，避免半句话就触发回复。
	visitorDebounceStep = 700 * time.Millisecond

	// visitorDebounceMax 是自适应窗口上限，防止访客持续连发时无限期推迟 flush。
	visitorDebounceMax = 4000 * time.Millisecond

	// typingHoldWindow 是一次入站 typing 信号的有效期：访客仍在输入时把 flush 推迟到此刻之后。
	// 取值需大于前端 typing 上报节流间隔（~2.5s），让连续输入期间的多帧信号无缝衔接；
	// 访客停止输入后不再有新帧，typingUntil 自然过期，最多多等这么久即 flush，不会无限挂起。
	typingHoldWindow = 4 * time.Second

	// typingFlushGrace 是 typing 静默后到真正 flush 之间的缓冲，给"刚抬手又补一句"留一点余地。
	typingFlushGrace = 300 * time.Millisecond
)

// debounceWindowFor 按缓冲里已堆积的消息条数计算本次静默窗口：
// 单条仍是基础窗口；连发越多窗口越长，封顶在 visitorDebounceMax。
func debounceWindowFor(pendingCount int) time.Duration {
	if pendingCount <= 1 {
		return visitorDebounceWindow
	}
	window := visitorDebounceWindow + visitorDebounceStep*time.Duration(pendingCount-1)
	if window > visitorDebounceMax {
		return visitorDebounceMax
	}
	return window
}

// pendingMessage 是 debounce 缓冲中的一条待处理访客消息。
// text 与 id 绑定在一起，保持访客文本和 DB 消息 ID 对齐。
type pendingMessage struct {
	text string
	id   string // DB message ID；PHP 侧未携带 ID 时为空字符串
}

// actorEvent 表示一次外部信号；目前覆盖访客消息和任务结果回流。
type actorEvent struct {
	kind string
	text string

	// visitorMessageIDs 是 visitor_message 事件聚合批次里所有访客消息的 DB ID，按发送顺序。
	// 最后一个会被用作最终 assistant 消息的 quoted_message_id。
	visitorMessageIDs []string

	taskID string
	status taskStatus
	result string
}

const (
	eventVisitorMessage = "visitor_message"
	eventTaskResult     = "task_result"
)

// taskStatus 表示任务 agent 的当前状态。
type taskStatus string

const (
	taskStatusRunning   taskStatus = "running"
	taskStatusDone      taskStatus = "done"
	taskStatusCancelled taskStatus = "cancelled"
	taskStatusFailed    taskStatus = "failed"
	taskStatusTimeout   taskStatus = "timeout"
)

// nativeCallFn 是 actor 调 PHP Native Action 的统一入口。
// 生产环境绑定 phpbridge.CallNative；测试时替换成 fake，不依赖 cgo + worker。
type nativeCallFn func(class string, params ...any) (any, error)

// runTurnFn 是一次接待 ReAct 推理的入口；抽成函数字段方便测试用 stub 直接控制行为。
// 返回值是本轮 LLM 与工具产生的新消息（assistant 含 tool_calls 与 tool 结果），
// 调用方将其追加进 actor.history 形成连续的 ReAct 上下文。
type runTurnFn func(ctx context.Context, in runTurnInput) ([]*schema.Message, error)

// errTaskLimitExceeded 用于 dispatch_task 检测并发任务上限。
var errTaskLimitExceeded = errors.New("task_limit_exceeded")

// Actor 是单个接待会话的长生命周期事件循环。
//
// 同一 conversationID 始终只有一个 Actor goroutine 在跑，history 在内存里持续累积：
// 访客消息（经 debounce 聚合）→ append user message → Runner.Run 一轮 → 把 LLM 输出的
// assistant (含 tool_call) 与工具结果整体回写进 history → 回到 select 等下一个事件。
//
// LLM 在第 N 轮看到的就是它自己第 1..N-1 轮的真实 tool_call/tool_result 流，
// 每轮只追加真实发生的消息与工具结果。
//
// 所有 bridge 调用以 conversation_id 为主键，让签名访客场景保持原会话归属。
type Actor struct {
	ctx    context.Context
	cancel context.CancelFunc

	native          nativeCallFn
	runTurn         runTurnFn
	runTurnFallback runTurnFallbackFn
	taskRunner      taskRunFn

	conversationID string

	events chan actorEvent

	// history 由 actor goroutine 单线程访问，无需加锁。
	history []*schema.Message

	// bootstrapped 标记 actor 是否已尝试从 DB 复活会话历史。
	// 进程重启后新 actor 内存为空，第一次 runOneTurn 时从 DB 拉一次过往消息填回 history，
	// 让 LLM 拿到完整对话上下文；标志位确保只跑一次，
	// 即使 bridge 调用失败也不会反复重试。
	bootstrapped bool

	// currentRuntime 在 runOneTurn 入口写入、出口清空，工具函数（在 Runner.Run 中同步执行）
	// 据此构造任务 agent 时拿到 provider / model。Runner.Run 内的工具调用与 runOneTurn 同一
	// goroutine，所以无需锁。
	currentRuntime *runtimeConfig

	// tasksMu 和 t.mu（TaskAgent 内部锁）存在固定的获取顺序：tasksMu → t.mu。
	// 任何代码路径都不能在持有 t.mu 时再加 tasksMu，否则会死锁。
	// workers 传给任务 agent 用于挂载 knowledge_search 等 PHP bridge 工具。
	workers frankenphp.Workers

	tasksMu sync.Mutex
	tasks   map[string]*TaskAgent

	pendingMu    sync.Mutex
	pending      []pendingMessage // 用结构体切片替代双平行切片，保证 text 与 id 始终对齐
	pendingTimer *time.Timer

	// typingUntil 是入站 typing 信号的「访客仍在输入」截止时刻（由 pendingMu 保护）。
	// flush 触发时若尚未到点，就把聚合推迟到该时刻之后，避免访客半句话就被回复。
	typingUntil time.Time

	// lastVisitorMessageID 由 runOneTurn 在处理 visitor_message 事件时更新。
	// 渠道开启引用访客消息时，runTurn 结束后会用它给最终 assistant 消息设置引用。
	// 该 ID 指向的消息若已被撤回，PHP 层 resolveQuotedMessageId 会让 AI 消息无 quote 落库。
	lastVisitorMessageID string

	handoffMu        sync.Mutex
	handoffRequested bool

	onExit func()
}

type actorDeps struct {
	ctx             context.Context
	cancel          context.CancelFunc
	native          nativeCallFn
	runTurn         runTurnFn
	runTurnFallback runTurnFallbackFn
	taskRunner      taskRunFn
	workers         frankenphp.Workers
	conversationID  string
	onExit          func()
}

func newActor(deps actorDeps) *Actor {
	if deps.runTurn == nil {
		deps.runTurn = runTurn
	}
	if deps.runTurnFallback == nil {
		deps.runTurnFallback = runTurnWithFallback
	}
	if deps.taskRunner == nil {
		deps.taskRunner = defaultTaskRun
	}
	return &Actor{
		ctx:             deps.ctx,
		cancel:          deps.cancel,
		native:          deps.native,
		runTurn:         deps.runTurn,
		runTurnFallback: deps.runTurnFallback,
		taskRunner:      deps.taskRunner,
		workers:         deps.workers,
		conversationID:  deps.conversationID,
		events:          make(chan actorEvent, eventBufferSize),
		tasks:           make(map[string]*TaskAgent),
		onExit:          deps.onExit,
	}
}

// enqueue 把外部事件投入 actor 的事件 chan。
// 缓冲满时直接丢弃当前事件并打日志，保持外部调用方快速返回。
func (a *Actor) enqueue(ev actorEvent) {
	select {
	case a.events <- ev:
	default:
		log.Printf("reception actor %s: event buffer full, dropping %s", a.conversationID, ev.kind)
	}
}

// acceptVisitorText 是 Registry 投递访客消息的入口：先做自适应 debounce 聚合，
// 静默窗口结束才把聚合后的消息以一个 visitor_message 事件入队。
// 窗口长度随缓冲里堆积的条数增长（见 debounceWindowFor），连发碎句时更有耐心。
//
// 多条消息按时间顺序用换行拼接，作为本轮完整上下文。
// messageID 是本次访客消息在 DB 中的 ID；批次里所有 ID 都会被透传给 actor，
// flush 时用最后一条作为最终 assistant 消息的 quoted_message_id；后续若任一 ID 被撤回，
// 整批 history 条目替换为占位。
func (a *Actor) acceptVisitorText(text, messageID string) {
	a.pendingMu.Lock()
	defer a.pendingMu.Unlock()

	a.pending = append(a.pending, pendingMessage{text: text, id: messageID})
	if a.pendingTimer != nil {
		a.pendingTimer.Stop()
	}
	a.pendingTimer = time.AfterFunc(debounceWindowFor(len(a.pending)), a.flushPendingVisitor)
}

// acceptRecall 处理访客撤回：仅作用于 debounce buffer 内尚未 flush 的消息。
//
// buffer 命中即同步删除；未命中表示消息已进入处理流程，直接丢弃撤回事件。
func (a *Actor) acceptRecall(messageID string) {
	if messageID == "" {
		return
	}

	a.pendingMu.Lock()
	defer a.pendingMu.Unlock()

	for i, m := range a.pending {
		if m.id != messageID {
			continue
		}
		a.pending = append(a.pending[:i], a.pending[i+1:]...)
		return
	}
}

// flushPendingVisitor 把聚合缓冲里的多条访客消息合成一条事件喂给 actor。
// time.AfterFunc 在独立 goroutine 中触发，因此需要锁保护 pending 状态。
func (a *Actor) flushPendingVisitor() {
	a.pendingMu.Lock()
	if len(a.pending) == 0 {
		a.pendingTimer = nil
		a.pendingMu.Unlock()
		return
	}
	// 访客仍在输入（web 渠道上报了 typing）：把 flush 推迟到输入静默之后，避免半句话就触发回复。
	if remaining := time.Until(a.typingUntil); remaining > 0 {
		a.pendingTimer = time.AfterFunc(remaining+typingFlushGrace, a.flushPendingVisitor)
		a.pendingMu.Unlock()
		return
	}
	msgs := a.pending
	a.pending = nil
	a.pendingTimer = nil
	a.pendingMu.Unlock()

	texts := make([]string, 0, len(msgs))
	var ids []string
	for _, m := range msgs {
		texts = append(texts, m.text)
		if m.id != "" {
			ids = append(ids, m.id)
		}
	}

	a.enqueue(actorEvent{
		kind:              eventVisitorMessage,
		text:              strings.Join(texts, "\n"),
		visitorMessageIDs: ids,
	})
}

// noteTyping 记录一次入站 typing 信号：把「访客仍在输入」截止时刻顺延 typingHoldWindow。
// 由 Registry 在 typing 端点反查到本 actor 后调用；与 acceptVisitorText / flush 共享 pendingMu。
func (a *Actor) noteTyping() {
	a.pendingMu.Lock()
	a.typingUntil = time.Now().Add(typingHoldWindow)
	a.pendingMu.Unlock()
}

// stopPendingTimer 在 actor 退出时取消尚未触发的 debounce 计时器。
func (a *Actor) stopPendingTimer() {
	a.pendingMu.Lock()
	defer a.pendingMu.Unlock()
	if a.pendingTimer != nil {
		a.pendingTimer.Stop()
		a.pendingTimer = nil
	}
	a.pending = nil
}

// markHandoff 在会话切到人工后设置标志，run loop 完成当前轮次后即退出。
func (a *Actor) markHandoff() {
	a.handoffMu.Lock()
	a.handoffRequested = true
	a.handoffMu.Unlock()
}

// shouldExit 返回是否应在当前轮结束后退出 actor。
func (a *Actor) shouldExit() bool {
	a.handoffMu.Lock()
	defer a.handoffMu.Unlock()
	return a.handoffRequested
}

// run 是 actor 的主循环：select 在事件、idle 超时、ctx 取消三者之间分派。
func (a *Actor) run() {
	defer func() {
		a.stopPendingTimer()
		a.cancelAllTasks()
		if a.onExit != nil {
			a.onExit()
		}
		a.cancel()
	}()

	idle := time.NewTimer(idleTimeout)
	defer idle.Stop()

	for {
		select {
		case <-a.ctx.Done():
			return

		case <-idle.C:
			log.Printf("reception actor %s: idle timeout, exiting", a.conversationID)
			return

		case ev := <-a.events:
			if !idle.Stop() {
				select {
				case <-idle.C:
				default:
				}
			}
			idle.Reset(idleTimeout)

			if err := a.runOneTurn(ev); err != nil {
				log.Printf("reception actor %s event failed: %v", a.conversationID, err)
			}

			if a.shouldExit() {
				return
			}
		}
	}
}

// runOneTurn 在收到一次事件时跑一遍 ReAct。
//
// 会话已由人工接手时，LoadReceptionRuntime 返回 available=false，actor 立即退出。
func (a *Actor) runOneTurn(ev actorEvent) error {
	conversationID := a.conversationID

	runtimeRes, err := a.native(
		"App\\Actions\\Native\\Reception\\LoadReceptionRuntimeBridgeAction",
		conversationID,
	)
	if err != nil {
		return fmt.Errorf("load runtime: %w", err)
	}

	runtime, err := decodeRuntime(runtimeRes)
	if err != nil {
		return fmt.Errorf("decode runtime: %w", err)
	}

	if !runtime.Available {
		log.Printf("reception actor %s: runtime unavailable (%s), exiting", conversationID, runtime.Reason)
		a.markHandoff()
		return nil
	}

	if !a.bootstrapped {
		// 首次运行时恢复已落库的文本消息；当前事件已包含的访客消息从恢复结果中排除。
		a.bootstrapped = true
		var skipIDs []string
		if ev.kind == eventVisitorMessage {
			skipIDs = ev.visitorMessageIDs
		}
		if err := a.bootstrapHistory(skipIDs); err != nil {
			log.Printf("reception actor %s: bootstrap history failed, continuing with empty history: %v", conversationID, err)
		}
	}

	if err := a.applyEventToHistory(ev); err != nil {
		return err
	}

	a.currentRuntime = &runtime
	defer func() { a.currentRuntime = nil }()

	// 抢占式重跑：本轮被新访客消息打断时，丢弃尚未投递的产物，把新消息并进 history 再跑一轮，
	// 最终一次访客连发只产出一条回复——避免「一句一回」的机械感。
	for {
		a.logEvent("reception_turn_started", map[string]any{
			"event_kind":       ev.kind,
			"history_depth":    len(a.history),
			"model_candidates": len(runtime.ModelCandidates),
		})

		res, preempt := a.runTurnAttempt(runtime)

		if errors.Is(res.err, errAllModelsExhausted) {
			a.handleAiUnavailable(runtime)
			// 即使全部模型耗尽，也保留已产出的消息——丢弃会让下一轮上下文断裂。
			a.history = append(a.history, res.emitted...)

			endPayload := buildTurnEndPayload("all_models_exhausted", res.emitted, len(a.history))
			endPayload["fallback_summary"] = formatFallbackSummary(runtime.ModelCandidates, res.fallbackResult)
			endPayload["error"] = aiintegration.SanitizeUpstreamError(res.err)
			a.logEvent("reception_turn_ended", endPayload)
			return nil
		}

		if preempt != nil {
			// 被新访客消息打断：丢弃本轮尚未投递的产物（不写 history、不投递）。
			// 打断前已触发的副作用（如 dispatch_task）保留，其结果稍后经 task_result 回流。
			a.logEvent("reception_turn_ended", buildTurnEndPayload("preempted", res.emitted, len(a.history)))

			if a.shouldExit() {
				// 打断前本轮已触发 handoff 等终态，notice 已送达访客，不再重跑。
				return nil
			}

			if err := a.applyEventToHistory(*preempt); err != nil {
				return err
			}
			ev = *preempt
			continue
		}

		var assistantDelivery map[string]any
		if res.err == nil {
			var deliveryErr error
			assistantDelivery, deliveryErr = a.deliverFinalAssistantMessage(res.emitted)
			if deliveryErr != nil {
				res.err = deliveryErr
			}
		}

		// 即使本轮出错也保留已捕获的消息——它们是 LLM 真实产出的上下文，丢弃会让下一轮断裂。
		a.history = append(a.history, res.emitted...)

		endedBy := classifyTurnSuccess(res.emitted)
		if res.err != nil {
			endedBy = classifyTurnError(res.err)
			log.Printf("reception actor %s: turn ended with %s: %v", conversationID, endedBy, res.err)
		}

		endPayload := buildTurnEndPayload(endedBy, res.emitted, len(a.history))
		if assistantDelivery != nil {
			endPayload["assistant_delivery"] = assistantDelivery
		}
		if res.fallbackResult.usedIndex > 0 {
			endPayload["fallback_summary"] = formatFallbackSummary(runtime.ModelCandidates, res.fallbackResult)
		}
		if res.err != nil {
			endPayload["error"] = aiintegration.SanitizeUpstreamError(res.err)
		}

		a.logEvent("reception_turn_ended", endPayload)
		return nil
	}
}

// turnAttemptResult 收敛一次 ReAct 推理（可能被抢占）的产物。
type turnAttemptResult struct {
	emitted        []*schema.Message
	fallbackResult modelFallbackResult
	err            error
}

// applyEventToHistory 把一次事件落进 actor.history：
// 访客消息转 user 消息并记下可引用的最后一条访客消息 ID；任务回流转成系统通知 user 消息。
func (a *Actor) applyEventToHistory(ev actorEvent) error {
	switch ev.kind {
	case eventVisitorMessage:
		if ev.text == "" {
			return fmt.Errorf("visitor_message event missing text")
		}
		a.history = append(a.history, schema.UserMessage(ev.text))
		if len(ev.visitorMessageIDs) > 0 {
			a.lastVisitorMessageID = ev.visitorMessageIDs[len(ev.visitorMessageIDs)-1]
		} else {
			// 当前批次没有可引用的访客消息 ID，后续最终 assistant 消息不携带引用。
			a.lastVisitorMessageID = ""
		}
	case eventTaskResult:
		a.history = append(a.history, schema.UserMessage(formatTaskResultNote(ev)))
	default:
		return fmt.Errorf("unknown actor event kind: %s", ev.kind)
	}
	return nil
}

// runTurnAttempt 跑一次 ReAct 推理，期间监听新访客消息以便抢占当前轮。
//
// 推理放在子 goroutine 里执行，actor goroutine 在此 select：
//   - 推理正常结束 → 返回结果，第二个返回值为 nil；
//   - 新访客消息到达 → cancel turnCtx 打断推理、等子 goroutine 收尾，返回该抢占事件，
//     调用方据此丢弃本轮产物、合并新消息后重跑；
//   - actor ctx 取消 → 取消推理并返回，由上层主循环退出。
//
// 推理期间到达的非访客事件（如任务回流）先暂存，attempt 返回后再放回事件队列，
// 既不丢事件，也不会在等待 LLM 时空转。
// 子 goroutine 只读取本轮快照 a.history，actor goroutine 在 attempt 期间不改 history，
// 保证 history 仍是单线程访问。
func (a *Actor) runTurnAttempt(runtime runtimeConfig) (turnAttemptResult, *actorEvent) {
	turnCtx, cancel := context.WithTimeout(a.ctx, turnTimeout)
	defer cancel()

	done := make(chan turnAttemptResult, 1)
	go func() {
		emitted, fallbackResult, err := a.runTurnFallback(turnCtx, runTurnInput{
			Actor:        a,
			SystemPrompt: runtime.SystemPrompt,
			Provider:     runtime.PrimaryModel.Provider,
			Model:        runtime.PrimaryModel.Model,
			History:      a.history,
		}, runtime.ModelCandidates)
		done <- turnAttemptResult{emitted: emitted, fallbackResult: fallbackResult, err: err}
	}()

	var deferred []actorEvent
	for {
		select {
		case res := <-done:
			a.requeueDeferredEvents(deferred)
			return res, nil

		case nev := <-a.events:
			if nev.kind == eventVisitorMessage {
				cancel()
				res := <-done
				a.requeueDeferredEvents(deferred)
				preempt := nev
				return res, &preempt
			}
			// 非访客事件推迟到本轮结束后再处理，避免在等待 LLM 时反复读取同一事件空转。
			deferred = append(deferred, nev)

		case <-a.ctx.Done():
			cancel()
			res := <-done
			return res, nil
		}
	}
}

// requeueDeferredEvents 把推理期间暂存的非访客事件放回事件队列，等本轮收尾后由主循环处理。
func (a *Actor) requeueDeferredEvents(deferred []actorEvent) {
	for _, ev := range deferred {
		a.enqueue(ev)
	}
}

// formatTaskResultNote 把任务回流事件翻译成喂给 LLM 的 user 消息。
// 不同状态用不同前缀，让 LLM 能直接区分"任务完成 vs 失败 vs 取消 vs 超时"。
func formatTaskResultNote(ev actorEvent) string {
	switch ev.status {
	case taskStatusDone:
		return fmt.Sprintf("[系统通知] 任务 %s 已完成：%s", ev.taskID, ev.result)
	case taskStatusFailed:
		return fmt.Sprintf("[系统通知] 任务 %s 失败：%s", ev.taskID, ev.result)
	case taskStatusCancelled:
		return fmt.Sprintf("[系统通知] 任务 %s 已取消", ev.taskID)
	case taskStatusTimeout:
		return fmt.Sprintf("[系统通知] 任务 %s 执行超时", ev.taskID)
	default:
		return fmt.Sprintf("[系统通知] 任务 %s 状态：%s", ev.taskID, ev.status)
	}
}

// bootstrapHistory 在 actor 首次执行时从 DB 复活历史 visitor / ai / teammate 文本消息到 history。
//
// 进程重启 / idle 超时后新 actor 内存为空，但 PHP DB 还保留着完整的对话流。这里只拉一次，
// 填回 actor.history 让 LLM 拿到上下文：visitor 消息映射成 UserMessage，ai 消息映射成
// AssistantMessage，teammate 消息作为上一位客服的可见回复放入 assistant 侧上下文。
//
// skipIDs 排除当前事件自带的访客消息 ID：触发首次 runOneTurn 的消息已经在
// sendMessageHandler 阶段写进 DB，bootstrap 会读到它；调用方稍后按 ev.text 再追加一次，
// 不排除就会同一条消息在 history 里出现两次。
//
// 已撤回与非文本消息在 PHP 层就被过滤掉，这里不会看到。
// 同时把最后一条 visitor 消息的 ID 写入 lastVisitorMessageID，让重启后第一次最终 assistant 消息
// 仍能引用访客最近发过的内容。
func (a *Actor) bootstrapHistory(skipIDs []string) error {
	if a.native == nil {
		return nil
	}

	res, err := a.native(
		"App\\Actions\\Native\\Reception\\LoadConversationHistoryBridgeAction",
		a.conversationID, nil,
	)
	if err != nil {
		return fmt.Errorf("load history: %w", err)
	}

	skip := make(map[string]struct{}, len(skipIDs))
	for _, id := range skipIDs {
		if id != "" {
			skip[id] = struct{}{}
		}
	}

	if res == nil {
		return nil
	}
	rows, ok := res.([]any)
	if !ok {
		// PHP bridge 的 handle() 类型签名是 array；若类型转换失败说明桥接层出了乱子，记一条日志便于定位。
		log.Printf("reception actor %s: bootstrap history returned unexpected type %T", a.conversationID, res)
		return nil
	}
	for _, raw := range rows {
		row, ok := raw.(map[string]any)
		if !ok {
			continue
		}
		role, _ := row["role"].(string)
		content, _ := row["content"].(string)
		id, _ := row["id"].(string)
		if content == "" {
			continue
		}
		if _, ok := skip[id]; ok {
			continue
		}

		switch role {
		case "visitor":
			a.history = append(a.history, schema.UserMessage(content))
			a.lastVisitorMessageID = id
		case "ai", "teammate":
			a.history = append(a.history, schema.AssistantMessage(content, nil))
		}
	}
	return nil
}

// deliverFinalAssistantMessage 把本轮最终 assistant 文本写入会话消息表。
func (a *Actor) deliverFinalAssistantMessage(messages []*schema.Message) (map[string]any, error) {
	text := finalAssistantText(messages)
	if text == "" {
		return nil, nil
	}

	quotedID := a.visitorQuoteID()
	delivery := map[string]any{
		"delivered":   false,
		"content_len": len([]rune(text)),
	}
	if quotedID != "" {
		delivery["quoted"] = quotedID
	}

	_, err := a.native(
		"App\\Actions\\Native\\Reception\\AppendAiMessageBridgeAction",
		a.conversationID, text, quotedID,
	)
	if err != nil {
		if bridgeErr := phpbridge.AsBridgeError(err); bridgeErr != nil && bridgeErr.IsClientError() {
			a.markHandoff()
		}
		return delivery, fmt.Errorf("deliver final assistant message: %w", err)
	}

	delivery["delivered"] = true
	return delivery, nil
}

// handleAiUnavailable 在所有候选模型都不可用时，通过 PHP bridge 发送兜底文案并将会话转为人工待接。
func (a *Actor) handleAiUnavailable(runtime runtimeConfig) {
	if runtime.AiUnavailableNotice == "" {
		log.Printf("reception actor %s: ai_unavailable_notice is empty, data pipeline issue", a.conversationID)
	}

	_, err := a.native(
		"App\\Actions\\Native\\Reception\\HandleAiUnavailableBridgeAction",
		a.conversationID, runtime.AiUnavailableNotice,
	)
	if err != nil {
		log.Printf("reception actor %s: handle ai unavailable failed: %v", a.conversationID, err)
	}
	a.markHandoff()
}

func (a *Actor) visitorQuoteID() string {
	if a.currentRuntime != nil && !a.currentRuntime.QuoteVisitorMessageEnabled {
		return ""
	}

	return a.lastVisitorMessageID
}

func finalAssistantText(messages []*schema.Message) string {
	for i := len(messages) - 1; i >= 0; i-- {
		msg := messages[i]
		if msg.Role != schema.Assistant || len(msg.ToolCalls) > 0 {
			continue
		}
		if text := strings.TrimSpace(msg.Content); text != "" {
			return text
		}
	}

	return ""
}

// logEvent 把一次接待节点写入 ConversationEvent；写库失败仅打 log，不阻断 actor 主流程。
func (a *Actor) logEvent(eventType string, payload map[string]any) {
	if a.native == nil {
		return
	}
	_, err := a.native(
		"App\\Actions\\Native\\Reception\\LogReceptionEventBridgeAction",
		a.conversationID, eventType, payload,
	)
	if err != nil {
		log.Printf("reception actor %s: log event %s failed: %v", a.conversationID, eventType, err)
	}
}

// dispatchTask 启动一个真任务 agent；同时对并发任务数做硬上限保护。
// 返回 errTaskLimitExceeded 时 dispatch_task 工具会把 task_limit_exceeded 透回给 LLM。
//
// 任务 agent 完成 / 失败 / 取消 / 超时后通过 enqueue(task_result) 回到本 actor 的事件循环，
// 由 LLM 决定后续回复或转人工等动作。
func (a *Actor) dispatchTask(question string) (string, error) {
	if a.currentRuntime == nil {
		return "", errors.New("dispatch_task called outside of a turn")
	}

	a.tasksMu.Lock()
	runningCount := 0
	for _, t := range a.tasks {
		if t.Status() == taskStatusRunning {
			runningCount++
		}
	}
	if runningCount >= maxConcurrentTasksPerConversation {
		a.tasksMu.Unlock()
		return "", errTaskLimitExceeded
	}

	task := newTaskAgent(
		a.ctx,
		taskRunInput{
			Question:         question,
			Provider:         a.currentRuntime.PrimaryTaskModel.Provider,
			Model:            a.currentRuntime.PrimaryTaskModel.Model,
			WorkspaceID:      a.currentRuntime.WorkspaceID,
			ServiceScenarios: a.currentRuntime.ServiceScenarios,
			KnowledgeBases:   a.currentRuntime.KnowledgeBases,
			McpServers:       a.currentRuntime.McpServers,
			Workers:          a.workers,
		},
		a.taskRunner,
	)
	a.tasks[task.ID()] = task
	a.tasksMu.Unlock()

	task.Start(func(c taskCompletion) {
		a.enqueue(actorEvent{
			kind:   eventTaskResult,
			taskID: c.taskID,
			status: c.status,
			result: c.result,
		})
	})

	return task.ID(), nil
}

// cancelTask 通过 task 的 ctx 取消正在运行的任务；任务不存在时返回 false。
func (a *Actor) cancelTask(taskID string) bool {
	a.tasksMu.Lock()
	task, ok := a.tasks[taskID]
	a.tasksMu.Unlock()
	if !ok {
		return false
	}
	task.Cancel()
	return true
}

// queryTask 返回任务的当前状态与最后一次结果。
func (a *Actor) queryTask(taskID string) (taskStatus, string, bool) {
	a.tasksMu.Lock()
	task, ok := a.tasks[taskID]
	a.tasksMu.Unlock()
	if !ok {
		return "", "", false
	}
	status, result := task.Snapshot()
	return status, result, true
}

// cancelAllTasks 在 actor 退出时取消所有任务 ctx，并停止后续任务回流。
func (a *Actor) cancelAllTasks() {
	a.tasksMu.Lock()
	tasks := make([]*TaskAgent, 0, len(a.tasks))
	for _, t := range a.tasks {
		tasks = append(tasks, t)
	}
	a.tasksMu.Unlock()
	for _, t := range tasks {
		t.Cancel()
	}
}

// classifyTurnError 把 Runner.Run 的退出原因归类成 ConversationEvent 中可读的 ended_by 取值。
// 直接判定 err：超时与取消由 runTurnFallback 透传 turnCtx 的 context 错误，无需再持有 ctx。
func classifyTurnError(err error) string {
	if errors.Is(err, context.DeadlineExceeded) {
		return "timeout"
	}
	if errors.Is(err, context.Canceled) {
		return "cancelled"
	}
	if errors.Is(err, errMaxIterations) {
		return "max_iterations"
	}
	return "error"
}

// classifyTurnSuccess 按本轮真实产出区分正常结束形态。
func classifyTurnSuccess(emitted []*schema.Message) string {
	if len(emitted) == 0 {
		return "empty"
	}

	hasToolResult := false
	hasToolCall := false
	hasAssistantText := false
	for _, msg := range emitted {
		if msg.Role == schema.Tool {
			hasToolResult = true
		}
		if len(msg.ToolCalls) > 0 {
			hasToolCall = true
		}
		if msg.Role == schema.Assistant && strings.TrimSpace(msg.Content) != "" {
			hasAssistantText = true
		}
	}

	switch {
	case hasToolResult:
		return "tool_done"
	case hasToolCall:
		return "tool_call"
	case hasAssistantText:
		return "assistant_message"
	default:
		return "message_done"
	}
}

func buildTurnEndPayload(endedBy string, emitted []*schema.Message, historyLen int) map[string]any {
	payload := map[string]any{
		"ended_by": endedBy,
		"emitted":  len(emitted),
		"history":  historyLen,
	}
	if len(emitted) > 0 {
		payload["messages"] = summarizeTurnMessages(emitted)
	}

	return payload
}

func summarizeTurnMessages(messages []*schema.Message) []map[string]any {
	summaries := make([]map[string]any, 0, len(messages))
	for i, msg := range messages {
		summary := map[string]any{
			"index": i,
			"role":  string(msg.Role),
		}
		if content := strings.TrimSpace(msg.Content); content != "" {
			summary["content"] = previewText(content)
			summary["content_len"] = len([]rune(content))
		}
		if msg.ToolName != "" {
			summary["tool_name"] = msg.ToolName
		}
		if msg.ToolCallID != "" {
			summary["tool_call_id"] = msg.ToolCallID
		}
		if len(msg.ToolCalls) > 0 {
			summary["tool_calls"] = summarizeToolCalls(msg.ToolCalls)
		}

		summaries = append(summaries, summary)
	}

	return summaries
}

func summarizeToolCalls(toolCalls []schema.ToolCall) []map[string]any {
	summaries := make([]map[string]any, 0, len(toolCalls))
	for _, toolCall := range toolCalls {
		summary := map[string]any{
			"id":   toolCall.ID,
			"name": toolCall.Function.Name,
		}
		if args := strings.TrimSpace(toolCall.Function.Arguments); args != "" {
			summary["arguments"] = previewText(args)
			summary["arguments_len"] = len([]rune(args))
		}
		summaries = append(summaries, summary)
	}

	return summaries
}

func previewText(text string) string {
	runes := []rune(text)
	if len(runes) <= turnPayloadPreviewLength {
		return text
	}

	return string(runes[:turnPayloadPreviewLength])
}

// errMaxIterations 在 ReAct 推理超过 maxIterations 时由 runTurn 返回，便于在 trace 里区分原因。
var errMaxIterations = errors.New("reception agent reached max iterations")

// newTaskID 生成 16 字符的 task_id，仅在单个 actor 内做映射，无需全局唯一。
func newTaskID() string {
	var b [8]byte
	_, _ = rand.Read(b[:])
	return "task_" + hex.EncodeToString(b[:])
}
