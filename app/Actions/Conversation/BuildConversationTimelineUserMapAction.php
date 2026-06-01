<?php

namespace App\Actions\Conversation;

use App\Models\User;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * 批量解析会话时间线事件里涉及的工作区成员名称。
 */
class BuildConversationTimelineUserMapAction
{
    use AsAction;

    /**
     * 从当前分页事件中提取用户 ID，并限制在当前工作区成员范围内查询名称。
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, string>
     */
    public function handle(Collection $rows, string $workspaceId): array
    {
        $userIds = $rows
            ->filter(fn (object $row): bool => (string) $row->type === 'event')
            ->flatMap(fn (object $row): array => $this->extractUserIds($row))
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->select('users.id', 'users.name')
            ->join('user_workspace', 'user_workspace.user_id', '=', 'users.id')
            ->where('user_workspace.workspace_id', $workspaceId)
            ->whereIn('users.id', $userIds->all())
            ->pluck('users.name', 'users.id')
            ->mapWithKeys(fn (string $name, string $id): array => [(string) $id => $name])
            ->all();
    }

    /**
     * 从事件行的 actor 和 payload 中提取可能参与展示的用户 ID。
     *
     * @return list<string>
     */
    private function extractUserIds(object $row): array
    {
        $payload = $this->decodePayload($row->payload);
        $ids = [];

        if ($row->actor_user_id !== null) {
            $ids[] = (string) $row->actor_user_id;
        }

        foreach (['user_id', 'previous_user_id'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $ids[] = (string) $payload[$key];
            }
        }

        return $ids;
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
