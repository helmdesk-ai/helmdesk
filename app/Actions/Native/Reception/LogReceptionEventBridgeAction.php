<?php

namespace App\Actions\Native\Reception;

use App\Enums\ConversationEventType;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Native bridge 入口：把接待 actor 关键节点写入 ConversationEvent。
 *
 * 接受三种事件类型（reception_turn_started / reception_tool_called / reception_turn_ended），
 * payload 由 Go 端组装，整体作为 JSON 落到 ConversationEvent.payload。
 */
class LogReceptionEventBridgeAction
{
    use AsAction;

    private const ALLOWED_TYPES = [
        'reception_turn_started',
        'reception_tool_called',
        'reception_turn_ended',
    ];

    /**
     * 按 conversation_id 查找会话并落一条接待事件，返回事件 id 供调用方做日志关联。
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{id: string}
     */
    public function handle(string $conversationId, string $type, ?array $payload = null): array
    {
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'unsupported reception event type']);
        }

        $conversation = Conversation::query()->find($conversationId);
        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        $event = ConversationEvent::query()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'type' => ConversationEventType::from($type),
            'payload' => $payload ?: null,
            'created_at' => now(),
        ]);

        return ['id' => (string) $event->id];
    }
}
