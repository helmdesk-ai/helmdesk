<?php

namespace App\Actions\Reception;

use App\Enums\ConversationAutoMessageTrigger;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 从 DB 加载指定会话的 visitor / AI / teammate 文本消息，供接待 actor 进程重启后复活内存历史。
 *
 * 按 seq_no 倒序取最近 N 条、再升序返回，保证历史顺序与对话时序一致。
 * 跳过 recalled / 非文本消息，避免把不可见内容放进模型上下文。
 */
class LoadConversationHistoryAction
{
    use AsAction;

    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    /**
     * 按 seq_no 升序返回 visitor + ai + teammate 文本消息列表。
     *
     * @param  int  $limit  返回上限，不超过 MAX_LIMIT
     * @return array<int, array{id: string, role: string, content: string}>
     */
    public function handle(Conversation $conversation, int $limit = self::DEFAULT_LIMIT): array
    {
        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', [MessageRole::Visitor, MessageRole::Ai, MessageRole::Teammate])
            ->where('kind', MessageKind::Text)
            ->whereNull('recalled_at')
            ->orderByDesc('seq_no')
            ->limit($limit)
            ->get(['id', 'role', 'content', 'payload', 'seq_no'])
            ->sortBy('seq_no')
            ->values();

        return $messages
            ->filter(fn (ConversationMessage $m): bool => is_string($m->content) && $m->content !== '' && $this->shouldIncludeInAiHistory($m))
            ->map(fn (ConversationMessage $m): array => [
                'id' => (string) $m->id,
                'role' => $m->role->value,
                'content' => (string) $m->content,
            ])
            ->values()
            ->all();
    }

    /**
     * AI 上下文只保留允许进入模型输入的自动回复。
     */
    private function shouldIncludeInAiHistory(ConversationMessage $message): bool
    {
        $payload = is_array($message->payload) ? $message->payload : [];
        if (($payload['source'] ?? null) !== 'auto_message') {
            return true;
        }

        return ($payload['trigger'] ?? null) === ConversationAutoMessageTrigger::AiWelcome->value;
    }
}
