package reception

import (
	"context"
	"sync"

	"github.com/dunglas/frankenphp"
	"github.com/dunglas/mercure"

	"helmdesk/internal/app/phpbridge"
)

// Registry 把每个会话的接待 actor 收敛到一张 map：同一 conversation 上的多条访客消息复用同一个 actor。
//
// actor 自然退出（idle / handoff / 上下文取消）时通过 onActorExit 自行从 map 中摘除。
// 进程退出时调用 ShutdownAll 统一 cancel 所有 actor 的 ctx。
type Registry struct {
	mu      sync.Mutex
	actors  map[string]*Actor
	workers frankenphp.Workers
	hub     *mercure.Hub

	// 入站 typing 信号的会话索引：访客发消息时由 Go 记下 session → conversation 映射，
	// typing 端点据 session 反查出 conversation 再唤起对应 actor 推迟 flush。
	// 全程不回 PHP，也不向访客暴露 conversation_id。两张表互为反向，便于 actor 退出时 O(1) 清理。
	sessionToConv map[string]string // sessionKey -> conversationID
	convToSession map[string]string // conversationID -> sessionKey
}

// NewRegistry 构造可被 reception 模块共享的 actor 注册中心。
//
// workers 为 nil 时 actor 内的 PHP bridge 调用会失败，调用方应在 wiring 阶段拒绝注入空 workers。
// hub 由 actor 复用同一条 Mercure 推送通道。
func NewRegistry(workers frankenphp.Workers, hub *mercure.Hub) *Registry {
	return &Registry{
		actors:        make(map[string]*Actor),
		workers:       workers,
		hub:           hub,
		sessionToConv: make(map[string]string),
		convToSession: make(map[string]string),
	}
}

// RememberVisitorSession 记录访客 session 与 conversation 的映射，供入站 typing 信号反查 actor。
// 访客每发一条消息时刷新一次；conversation 改绑新 session 时清掉旧索引，避免悬挂条目。
func (r *Registry) RememberVisitorSession(sessionKey, conversationID string) {
	if sessionKey == "" || conversationID == "" {
		return
	}

	r.mu.Lock()
	defer r.mu.Unlock()

	if oldKey, ok := r.convToSession[conversationID]; ok && oldKey != sessionKey {
		delete(r.sessionToConv, oldKey)
	}
	r.sessionToConv[sessionKey] = conversationID
	r.convToSession[conversationID] = sessionKey
}

// NoteTyping 处理一次入站 typing 信号：按 session 反查 conversation，唤起对应 actor 推迟 flush。
// 找不到映射或 actor 已退出（会话结束/转人工）时静默忽略——typing 只是优化，不应反向拉起 actor。
func (r *Registry) NoteTyping(sessionKey string) {
	if sessionKey == "" {
		return
	}

	r.mu.Lock()
	conversationID := r.sessionToConv[sessionKey]
	actor, ok := r.actors[conversationID]
	if conversationID != "" && !ok {
		// actor 已退出，顺手清掉悬挂索引。
		delete(r.sessionToConv, sessionKey)
		delete(r.convToSession, conversationID)
	}
	r.mu.Unlock()

	if !ok {
		return
	}
	actor.noteTyping()
}

// forgetVisitorSession 在 actor 退出时清掉其 session 索引；持有 r.mu 时调用。
func (r *Registry) forgetVisitorSession(conversationID string) {
	if key, ok := r.convToSession[conversationID]; ok {
		delete(r.sessionToConv, key)
		delete(r.convToSession, conversationID)
	}
}

// EnqueueVisitorMessage 把 "访客刚发了一条消息" 这一信号送给对应 actor。
// 若 actor 不存在则按 conversationID 启动新 actor 并立即唤醒。
//
// text 是访客本次发送的原文；messageID 是这条访客消息在 DB 中的 ID。actor 内部会用
// 1.5s debounce 把多条快速连发的消息聚合成一条 user message 喂给 LLM；同时 debounce
// 批次里最后一条的 messageID 会被 actor 记下来；渠道开启引用访客消息时，
// AI 后续普通回复会把它作为 quoted_message_id 写回会话。
//
// 此调用本身不阻塞访客 HTTP 响应：actor 的 ReAct 推理整体在后台 goroutine 中跑。
func (r *Registry) EnqueueVisitorMessage(conversationID, text, messageID string) {
	actor := r.getOrStart(conversationID)
	actor.acceptVisitorText(text, messageID)
}

// NotifyMessageRecalled 通知 actor 一条访客消息已被撤回。
// 仅给已存在的 actor 投递通知——为撤回单独拉起 actor 没有意义（没 buffer 可清理）。
//
// actor 仅作用于 debounce buffer 内尚未 flush 的消息：buffer 命中即同步删除该项；
// 命中不到（消息已 flush 进 history）就直接丢弃，actor 既不改 history 也不取消任务。
// 这对齐"撤回来得太晚"的真人客服语义：AI 已经动手处理过的消息不再回滚。
func (r *Registry) NotifyMessageRecalled(conversationID, messageID string) {
	r.mu.Lock()
	actor, ok := r.actors[conversationID]
	r.mu.Unlock()
	if !ok {
		return
	}
	actor.acceptRecall(messageID)
}

// ShutdownAll cancel 所有在跑的 actor，常用于进程退出场景。
// 调用后 Registry 仍可继续接收新 EnqueueVisitorMessage，新 actor 会在新的 ctx 上启动。
func (r *Registry) ShutdownAll() {
	r.mu.Lock()
	actors := make([]*Actor, 0, len(r.actors))
	for _, a := range r.actors {
		actors = append(actors, a)
	}
	r.mu.Unlock()

	for _, a := range actors {
		a.cancel()
	}
}

// getOrStart 取或新建 actor；同 conversationID 同一时刻只跑一个 actor goroutine。
func (r *Registry) getOrStart(conversationID string) *Actor {
	r.mu.Lock()
	defer r.mu.Unlock()

	if existing, ok := r.actors[conversationID]; ok {
		return existing
	}

	ctx, cancel := context.WithCancel(context.Background())
	workers := r.workers
	actor := newActor(actorDeps{
		ctx:    ctx,
		cancel: cancel,
		native: func(class string, params ...any) (any, error) {
			return phpbridge.CallNative(workers, class, params...)
		},
		workers:        workers,
		conversationID: conversationID,
		onExit: func() {
			r.mu.Lock()
			if r.actors[conversationID] == nil {
				r.mu.Unlock()
				return
			}
			delete(r.actors, conversationID)
			r.forgetVisitorSession(conversationID)
			r.mu.Unlock()
		},
	})
	r.actors[conversationID] = actor
	go actor.run()
	return actor
}
