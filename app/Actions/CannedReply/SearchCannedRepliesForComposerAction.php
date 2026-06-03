<?php

namespace App\Actions\CannedReply;

use App\Data\CannedReply\CannedReplyComposerItemData;
use App\Models\CannedReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 收件箱回复 composer 中"快捷回复"候选项搜索接口。
 *
 * v1：纯关键字 LIKE + 使用频率 / 最近使用排序，relevance_score 留口为 0。
 * v2：在 handle() 内部叠加 embedding 检索 + 重排，签名和返回结构都不变。
 *
 * 入参 $conversationId 在 v1 不参与排序，仅做参数校验保留；
 * v2 用它从对话上下文中提取语义信号给 embedding 模型。
 */
class SearchCannedRepliesForComposerAction
{
    use AsAction;

    private const DEFAULT_LIMIT = 8;

    private const MAX_LIMIT = 20;

    private const MAX_QUERY_LENGTH = 64;

    /**
     * 搜索当前用户可见的快捷回复模版（个人 + 系统共享）。
     *
     * @return array<int, CannedReplyComposerItemData>
     */
    public function handle(
        User $user,
        ?string $conversationId,
        string $query,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $normalizedQuery = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);

        $builder = CannedReply::query()
            ->where(function (Builder $scope) use ($user): void {
                $scope->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            });

        if ($normalizedQuery !== '') {
            $like = '%'.$normalizedQuery.'%';
            $shortcutPrefix = $normalizedQuery.'%';

            $builder->where(function (Builder $scope) use ($like, $shortcutPrefix): void {
                $scope
                    ->where('shortcut', 'like', $shortcutPrefix)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('content', 'like', $like);
            });
        }

        $replies = $builder
            ->orderByRaw('CASE WHEN user_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('last_used_at IS NULL')
            ->orderByDesc('last_used_at')
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return $replies->map(
            fn (CannedReply $reply) => CannedReplyComposerItemData::fromModel($reply, relevanceScore: 0.0),
        )->all();
    }

    /**
     * XHR 入口：返回 JSON 数组给前端 composer。
     */
    public function asController(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversationId = $request->query('conversation_id');
        $query = $request->query('q');
        $rawLimit = $request->query('limit');

        $items = $this->handle(
            user: $user,
            conversationId: is_string($conversationId) && $conversationId !== '' ? $conversationId : null,
            query: is_string($query) ? $query : '',
            limit: is_numeric($rawLimit) ? (int) $rawLimit : self::DEFAULT_LIMIT,
        );

        return response()->json([
            'items' => array_map(static fn (CannedReplyComposerItemData $item) => $item->toArray(), $items),
        ]);
    }
}
