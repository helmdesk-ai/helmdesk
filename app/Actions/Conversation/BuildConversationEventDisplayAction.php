<?php

namespace App\Actions\Conversation;

use App\Data\Conversation\ConversationEventDisplayData;
use App\Data\Conversation\ConversationEventFactData;
use App\Enums\AutoMessageTranslationFailureMode;
use App\Enums\ConversationAutoMessageTrigger;
use App\Enums\ConversationEventDisplayMode;
use App\Enums\ConversationEventSemanticType;
use App\Enums\ConversationEventTone;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\Reception\HumanServiceUnavailableReason;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * 将会话事件 payload 转换为客服可读的时间线活动展示数据。
 */
class BuildConversationEventDisplayAction
{
    use AsAction;

    /**
     * 根据事件类型和 payload 生成结构化展示数据。
     *
     * @param  array<string, string>  $userNamesById
     */
    public function handle(object $row, array $userNamesById): ConversationEventDisplayData
    {
        $eventType = ConversationEventType::tryFrom((string) $row->event_type)
            ?? throw new RuntimeException('Unknown conversation event type: '.(string) $row->event_type);
        $payload = $this->decodePayload($row->payload);

        return match ($eventType) {
            ConversationEventType::Created => $this->created($row, $payload, $userNamesById),
            ConversationEventType::HandoffRequested => $this->handoffRequested($payload),
            ConversationEventType::AssignmentChanged => $this->assignmentChanged($row, $payload, $userNamesById),
            ConversationEventType::StatusChanged => $this->statusChanged($row, $payload, $userNamesById),
            ConversationEventType::ReceptionToolCalled => $this->receptionToolCalled($payload),
            ConversationEventType::ReceptionTurnEnded => $this->receptionTurnEnded($payload),
            ConversationEventType::AutoMessageTranslationFailed => $this->autoMessageTranslationFailed($payload),
            ConversationEventType::ReceptionTurnStarted => throw new RuntimeException('Reception turn started is not a timeline display event.'),
        };
    }

    /**
     * 构建会话创建事件展示。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $userNamesById
     */
    private function created(object $row, array $payload, array $userNamesById): ConversationEventDisplayData
    {
        $source = $this->requiredPayloadString($payload, 'source');

        $summary = match ($source) {
            'reception' => __('conversation.event_displays.created.reception'),
            'manual' => __('conversation.event_displays.created.manual', [
                'actor' => $this->actorNameOrSystem($row, $userNamesById),
            ]),
            default => throw new RuntimeException('Unknown conversation created source: '.$source),
        };

        return new ConversationEventDisplayData(
            summary: $summary,
            detail: null,
            semantic_type: ConversationEventSemanticType::Conversation,
            tone: ConversationEventTone::Muted,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建 AI 请求人工介入事件展示。
     *
     * @param  array<string, mixed>  $payload
     */
    private function handoffRequested(array $payload): ConversationEventDisplayData
    {
        $reason = $this->requiredPayloadString($payload, 'reason');
        [$summary, $tone] = match ($reason) {
            'user_requested' => [
                __('conversation.event_displays.handoff_requested.user_requested'),
                ConversationEventTone::Normal,
            ],
            'ai_requested' => [
                __('conversation.event_displays.handoff_requested.ai_requested'),
                ConversationEventTone::Important,
            ],
            'low_confidence' => [
                __('conversation.event_displays.handoff_requested.low_confidence'),
                ConversationEventTone::Normal,
            ],
            'tool_failure' => [
                __('conversation.event_displays.handoff_requested.tool_failure'),
                ConversationEventTone::Warning,
            ],
            'policy_required' => [
                __('conversation.event_displays.handoff_requested.policy_required'),
                ConversationEventTone::Normal,
            ],
            'ai_unavailable' => [
                __('conversation.event_displays.handoff_requested.ai_unavailable'),
                ConversationEventTone::Warning,
            ],
            default => throw new RuntimeException('Unknown handoff reason: '.$reason),
        };

        return new ConversationEventDisplayData(
            summary: $summary,
            detail: null,
            semantic_type: ConversationEventSemanticType::BotAction,
            tone: $tone,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建分配变更事件展示。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $userNamesById
     */
    private function assignmentChanged(object $row, array $payload, array $userNamesById): ConversationEventDisplayData
    {
        $source = $this->requiredPayloadString($payload, 'source');

        return match ($source) {
            'claim' => $this->claimAssignment($row, $userNamesById),
            'reply' => $this->replyAssignment($row, $userNamesById),
            'transfer_to_human' => $this->humanTransferAssignment($row, $userNamesById),
            'takeover' => $this->takeoverAssignment($row, $payload, $userNamesById),
            'transfer_to_teammate' => $this->teammateTransferAssignment($row, $payload, $userNamesById),
            'release_to_ai' => $this->releaseToAiAssignment($row, $userNamesById),
            default => throw new RuntimeException('Unknown assignment_changed source: '.$source),
        };
    }

    /**
     * 构建普通接单事件。
     *
     * @param  array<string, string>  $userNamesById
     */
    private function claimAssignment(object $row, array $userNamesById): ConversationEventDisplayData
    {
        $actor = $this->requiredActorName($row, $userNamesById);

        return new ConversationEventDisplayData(
            summary: __('conversation.event_displays.assignment_changed.claim', ['actor' => $actor]),
            detail: null,
            semantic_type: ConversationEventSemanticType::UserAction,
            tone: ConversationEventTone::Normal,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建回复时自动接管事件。
     *
     * @param  array<string, string>  $userNamesById
     */
    private function replyAssignment(object $row, array $userNamesById): ConversationEventDisplayData
    {
        $actor = $this->requiredActorName($row, $userNamesById);

        return new ConversationEventDisplayData(
            summary: __('conversation.event_displays.assignment_changed.reply', ['actor' => $actor]),
            detail: null,
            semantic_type: ConversationEventSemanticType::UserAction,
            tone: ConversationEventTone::Normal,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建 AI 转人工后的接管事件。
     *
     * @param  array<string, string>  $userNamesById
     */
    private function humanTransferAssignment(object $row, array $userNamesById): ConversationEventDisplayData
    {
        $actor = $this->requiredActorName($row, $userNamesById);

        return new ConversationEventDisplayData(
            summary: __('conversation.event_displays.assignment_changed.transfer_to_human', ['actor' => $actor]),
            detail: null,
            semantic_type: ConversationEventSemanticType::UserAction,
            tone: ConversationEventTone::Normal,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建从其他客服接管事件。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $userNamesById
     */
    private function takeoverAssignment(object $row, array $payload, array $userNamesById): ConversationEventDisplayData
    {
        $actor = $this->requiredActorName($row, $userNamesById);
        $previousUser = $this->requiredPayloadUserName($payload, 'previous_user_id', $userNamesById);

        return new ConversationEventDisplayData(
            summary: __('conversation.event_displays.assignment_changed.takeover', ['actor' => $actor, 'previous_user' => $previousUser]),
            detail: null,
            semantic_type: ConversationEventSemanticType::UserAction,
            tone: ConversationEventTone::Normal,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建客服之间转接事件。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $userNamesById
     */
    private function teammateTransferAssignment(object $row, array $payload, array $userNamesById): ConversationEventDisplayData
    {
        $actor = $this->requiredActorName($row, $userNamesById);
        $target = $this->requiredPayloadUserName($payload, 'user_id', $userNamesById);

        return new ConversationEventDisplayData(
            summary: __('conversation.event_displays.assignment_changed.transfer_to_teammate', ['actor' => $actor, 'target' => $target]),
            detail: null,
            semantic_type: ConversationEventSemanticType::UserAction,
            tone: ConversationEventTone::Normal,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建交回 AI 或待接队列事件。
     *
     * @param  array<string, string>  $userNamesById
     */
    private function releaseToAiAssignment(object $row, array $userNamesById): ConversationEventDisplayData
    {
        $actor = $this->requiredActorName($row, $userNamesById);

        return new ConversationEventDisplayData(
            summary: __('conversation.event_displays.assignment_changed.release_to_ai', ['actor' => $actor]),
            detail: null,
            semantic_type: ConversationEventSemanticType::BotAction,
            tone: ConversationEventTone::Muted,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建会话状态变更事件。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $userNamesById
     */
    private function statusChanged(object $row, array $payload, array $userNamesById): ConversationEventDisplayData
    {
        $actor = $this->actorNameOrSystem($row, $userNamesById);
        $status = ConversationStatus::tryFrom($this->requiredPayloadString($payload, 'status'))
            ?? throw new RuntimeException('Unknown conversation status.');

        if ($status === ConversationStatus::Open) {
            return new ConversationEventDisplayData(
                summary: __('conversation.event_displays.status_changed.open', ['actor' => $actor]),
                detail: null,
                semantic_type: ConversationEventSemanticType::StatusChange,
                tone: ConversationEventTone::Normal,
                display_mode: ConversationEventDisplayMode::Inline,
            );
        }

        return new ConversationEventDisplayData(
            summary: __('conversation.event_displays.status_changed.closed', ['actor' => $actor]),
            detail: null,
            semantic_type: ConversationEventSemanticType::StatusChange,
            tone: ConversationEventTone::Muted,
            display_mode: ConversationEventDisplayMode::Inline,
            facts: [],
        );
    }

    /**
     * 构建 AI 工具调用事件。
     *
     * @param  array<string, mixed>  $payload
     */
    private function receptionToolCalled(array $payload): ConversationEventDisplayData
    {
        $tool = $this->requiredPayloadString($payload, 'tool');

        return match ($tool) {
            'dispatch_task' => $this->dispatchTaskCalled($payload),
            'cancel_task' => $this->cancelTaskCalled($payload),
            'handoff_to_human' => $this->handoffToHumanCalled($payload),
            default => throw new RuntimeException('Unsupported reception tool display: '.$tool),
        };
    }

    /**
     * 构建 AI 派发后台任务事件。
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatchTaskCalled(array $payload): ConversationEventDisplayData
    {
        $result = $this->optionalPayloadString($payload, 'result');

        if ($result === 'task_limit_exceeded') {
            return new ConversationEventDisplayData(
                summary: __('conversation.event_displays.reception_tool_called.dispatch_task_limit.summary'),
                detail: null,
                semantic_type: ConversationEventSemanticType::Warning,
                tone: ConversationEventTone::Warning,
                display_mode: ConversationEventDisplayMode::Inline,
            );
        }

        if ($result !== null) {
            throw new RuntimeException('Unsupported dispatch_task result: '.$result);
        }

        return new ConversationEventDisplayData(
            summary: __('conversation.event_displays.reception_tool_called.dispatch_task.summary'),
            detail: null,
            semantic_type: ConversationEventSemanticType::ToolCall,
            tone: ConversationEventTone::Muted,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建 AI 取消后台任务事件。
     *
     * @param  array<string, mixed>  $payload
     */
    private function cancelTaskCalled(array $payload): ConversationEventDisplayData
    {
        $result = $this->requiredPayloadString($payload, 'result');
        if ($result !== 'cancelled') {
            throw new RuntimeException('Unsupported cancel_task result: '.$result);
        }

        return new ConversationEventDisplayData(
            summary: __('conversation.event_displays.reception_tool_called.cancel_task.summary'),
            detail: null,
            semantic_type: ConversationEventSemanticType::BotAction,
            tone: ConversationEventTone::Muted,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建 AI 尝试转人工但人工不可用事件。
     *
     * @param  array<string, mixed>  $payload
     */
    private function handoffToHumanCalled(array $payload): ConversationEventDisplayData
    {
        $accepted = $this->requiredPayloadBool($payload, 'accepted');
        if ($accepted) {
            throw new RuntimeException('Unsupported handoff_to_human accepted state.');
        }

        $reason = $this->requiredPayloadString($payload, 'reason');
        [$summary, $tone] = match (HumanServiceUnavailableReason::tryFrom($reason)) {
            HumanServiceUnavailableReason::NoOnlineTeammate => [
                __('conversation.event_displays.reception_tool_called.handoff_unavailable.no_online_teammate'),
                ConversationEventTone::Important,
            ],
            HumanServiceUnavailableReason::OutsideBusinessHours => [
                __('conversation.event_displays.reception_tool_called.handoff_unavailable.outside_business_hours'),
                ConversationEventTone::Muted,
            ],
            null => throw new RuntimeException('Unknown handoff unavailable reason: '.$reason),
        };

        return new ConversationEventDisplayData(
            summary: $summary,
            detail: null,
            semantic_type: ConversationEventSemanticType::Warning,
            tone: $tone,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建 AI 本轮接待异常结束事件。
     *
     * @param  array<string, mixed>  $payload
     */
    private function receptionTurnEnded(array $payload): ConversationEventDisplayData
    {
        $endedBy = $this->requiredPayloadString($payload, 'ended_by');
        [$summary, $tone] = match ($endedBy) {
            'timeout' => [
                __('conversation.event_displays.reception_turn_ended.timeout'),
                ConversationEventTone::Important,
            ],
            'error' => [
                __('conversation.event_displays.reception_turn_ended.error'),
                ConversationEventTone::Warning,
            ],
            'max_iterations' => [
                __('conversation.event_displays.reception_turn_ended.max_iterations'),
                ConversationEventTone::Important,
            ],
            default => throw new RuntimeException('Unsupported reception turn end display: '.$endedBy),
        };

        return new ConversationEventDisplayData(
            summary: $summary,
            detail: null,
            semantic_type: ConversationEventSemanticType::Warning,
            tone: $tone,
            display_mode: ConversationEventDisplayMode::Inline,
        );
    }

    /**
     * 构建自动回复翻译失败事件。
     *
     * @param  array<string, mixed>  $payload
     */
    private function autoMessageTranslationFailed(array $payload): ConversationEventDisplayData
    {
        $mode = AutoMessageTranslationFailureMode::tryFrom($this->requiredPayloadString($payload, 'mode'))
            ?? throw new RuntimeException('Unknown auto message translation failure mode.');
        $trigger = ConversationAutoMessageTrigger::tryFrom($this->requiredPayloadString($payload, 'trigger'))
            ?? throw new RuntimeException('Unknown auto message trigger.');

        return new ConversationEventDisplayData(
            summary: __('conversation.event_displays.auto_message_translation_failed.'.$mode->value),
            detail: $this->requiredPayloadString($payload, 'content'),
            semantic_type: ConversationEventSemanticType::Warning,
            tone: ConversationEventTone::Warning,
            display_mode: ConversationEventDisplayMode::Inline,
            facts: [$this->fact(__('conversation.event_displays.facts.auto_message'), $trigger->label())],
        );
    }

    /**
     * 生成事件事实项。
     */
    private function fact(string $label, string $value): ConversationEventFactData
    {
        return new ConversationEventFactData($label, $value);
    }

    /**
     * 读取必需的 payload 字符串字段。
     *
     * @param  array<string, mixed>  $payload
     */
    private function requiredPayloadString(array $payload, string $key): string
    {
        if (! isset($payload[$key]) || ! is_scalar($payload[$key]) || (string) $payload[$key] === '') {
            throw new RuntimeException('Missing conversation event payload field: '.$key);
        }

        return (string) $payload[$key];
    }

    /**
     * 读取可选的 payload 字符串字段。
     *
     * @param  array<string, mixed>  $payload
     */
    private function optionalPayloadString(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        if (! is_scalar($payload[$key]) || (string) $payload[$key] === '') {
            throw new RuntimeException('Invalid conversation event payload field: '.$key);
        }

        return (string) $payload[$key];
    }

    /**
     * 读取必需的 payload 布尔字段。
     *
     * @param  array<string, mixed>  $payload
     */
    private function requiredPayloadBool(array $payload, string $key): bool
    {
        if (! array_key_exists($key, $payload) || ! is_bool($payload[$key])) {
            throw new RuntimeException('Missing conversation event payload boolean field: '.$key);
        }

        return $payload[$key];
    }

    /**
     * 读取 payload 中的用户 ID 并解析成员名称。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $userNamesById
     */
    private function requiredPayloadUserName(array $payload, string $key, array $userNamesById): string
    {
        $userId = $this->requiredPayloadString($payload, $key);

        return $userNamesById[$userId] ?? throw new RuntimeException('Unknown workspace user in conversation event: '.$userId);
    }

    /**
     * 解析事件 actor 名称；无 actor 时返回系统。
     *
     * @param  array<string, string>  $userNamesById
     */
    private function actorNameOrSystem(object $row, array $userNamesById): string
    {
        if ($row->actor_user_id === null) {
            return __('conversation.event_displays.actors.system');
        }

        return $this->requiredActorName($row, $userNamesById);
    }

    /**
     * 解析事件 actor 名称，缺失时显性失败。
     *
     * @param  array<string, string>  $userNamesById
     */
    private function requiredActorName(object $row, array $userNamesById): string
    {
        if ($row->actor_user_id === null) {
            throw new RuntimeException('Conversation event actor_user_id is required.');
        }

        $userId = (string) $row->actor_user_id;

        return $userNamesById[$userId] ?? throw new RuntimeException('Unknown workspace actor in conversation event: '.$userId);
    }

    /**
     * 将数据库 payload 统一解码为数组。
     *
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Conversation event payload is not a valid object.');
        }

        return $decoded;
    }
}
