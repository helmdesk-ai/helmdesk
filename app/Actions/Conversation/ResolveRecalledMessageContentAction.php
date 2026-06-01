<?php

namespace App\Actions\Conversation;

use App\Enums\MessageRole;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 决定是否给已撤回消息下发原文。
 *
 * 客服自己发的 teammate 消息或工作区 AI 消息可被重新编辑，访客消息永不下发。
 * 会话详情和联系人时间线两个 Action 都基于这套规则下发 recalled_content。
 */
class ResolveRecalledMessageContentAction
{
    use AsAction;

    /**
     * $row 是 selectRaw 取出来的时间线 stdClass，需要至少包含 role / actor_user_id / content。
     */
    public function handle(object $row, bool $isRecalled, ?User $viewer): ?string
    {
        if (! $isRecalled || $viewer === null || $row->content === null) {
            return null;
        }

        $role = (string) $row->role;
        $actor = $row->actor_user_id !== null ? (string) $row->actor_user_id : null;
        $viewerText = $this->viewerText($row->payload ?? null, $viewer->locale);

        if ($role === MessageRole::Teammate->value && $actor !== null && $actor === (string) $viewer->id) {
            return $viewerText ?? (string) $row->content;
        }

        if ($role === MessageRole::Ai->value) {
            return $viewerText ?? (string) $row->content;
        }

        return null;
    }

    /**
     * 读取当前客服语言下的消息内容。
     */
    private function viewerText(mixed $payload, string $locale): ?string
    {
        $decoded = is_array($payload)
            ? $payload
            : (is_string($payload) && $payload !== '' ? json_decode($payload, true) : null);

        $text = is_array($decoded) ? ($decoded['translations'][$locale]['text'] ?? null) : null;

        return is_string($text) && $text !== '' ? $text : null;
    }
}
