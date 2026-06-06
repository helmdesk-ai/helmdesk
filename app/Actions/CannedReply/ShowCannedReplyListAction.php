<?php

namespace App\Actions\CannedReply;

use App\Data\CannedReply\CannedReplyTokenOptionData;
use App\Data\CannedReply\ListCannedReplyItemData;
use App\Data\CannedReply\ShowCannedReplyListPagePropsData;
use App\Enums\UserPermission;
use App\Models\CannedReply;
use App\Models\User;
use App\Services\CannedReply\CannedReplyPermission;
use App\Services\CannedReply\CannedReplyVariableResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示快捷回复模版列表。
 * 当前用户可见的模版 = 自己的个人模版 + 系统共享模版；
 * visibility 参数支持过滤"全部 / 共享 / 个人"。
 */
class ShowCannedReplyListAction
{
    use AsAction;

    public const VISIBILITY_ALL = 'all';

    public const VISIBILITY_SYSTEM = 'system';

    public const VISIBILITY_PERSONAL = 'personal';

    /**
     * 注入访问策略与变量解析器。
     */
    public function __construct(
        private readonly CannedReplyPermission $policy,
        private readonly CannedReplyVariableResolver $resolver,
    ) {}

    /**
     * 组装列表页面 props。
     */
    public function handle(User $user, string $visibility = self::VISIBILITY_ALL): ShowCannedReplyListPagePropsData
    {
        $visibility = $this->normalizeVisibility($visibility);

        $query = CannedReply::query()
            ->with('owner')
            ->where(function (Builder $scope) use ($user): void {
                $scope->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            });

        $this->applyVisibilityScope($query, $user, $visibility);

        $replies = $query
            ->orderByRaw('CASE WHEN user_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('last_used_at IS NULL')
            ->orderByDesc('last_used_at')
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->get();

        $items = $replies->map(fn (CannedReply $reply) => ListCannedReplyItemData::fromModel(
            $reply,
            canEdit: $this->policy->canEdit($reply, $user),
            canDelete: $this->policy->canDelete($reply, $user),
        ))->all();

        return new ShowCannedReplyListPagePropsData(
            canned_reply_list: $items,
            can_manage_system_replies: $this->policy->canManageSystemShared($user),
            current_visibility: $visibility,
            available_tokens: array_map(
                static fn (array $token) => CannedReplyTokenOptionData::fromArray($token),
                $this->resolver->availableTokens(),
            ),
        );
    }

    /**
     * Inertia 入口：解析请求参数并渲染列表页。
     */
    public function asController(Request $request): Response
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('user.permission', UserPermission::CannedRepliesView);

        $visibility = $request->query('visibility');
        $props = $this->handle(
            $user,
            is_string($visibility) ? $visibility : self::VISIBILITY_ALL,
        );

        return Inertia::render('cannedReplies/Index', $props->toArray());
    }

    /**
     * 把任意输入归一化到允许的 visibility 值。
     */
    private function normalizeVisibility(string $value): string
    {
        return match ($value) {
            self::VISIBILITY_SYSTEM, self::VISIBILITY_PERSONAL => $value,
            default => self::VISIBILITY_ALL,
        };
    }

    /**
     * 在外层"个人 + 共享"基础上叠加更细粒度的归属筛选。
     */
    private function applyVisibilityScope(Builder $query, User $user, string $visibility): void
    {
        match ($visibility) {
            self::VISIBILITY_SYSTEM => $query->whereNull('user_id'),
            self::VISIBILITY_PERSONAL => $query->where('user_id', $user->id),
            default => null,
        };
    }
}
