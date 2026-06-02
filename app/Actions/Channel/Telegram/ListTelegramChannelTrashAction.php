<?php

namespace App\Actions\Channel\Telegram;

use App\Data\Channel\Telegram\ShowTelegramChannelTrashPagePropsData;
use App\Data\Channel\Telegram\TelegramChannelData;
use App\Data\SimplePaginationData;
use App\Data\SystemUserContextData;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\SystemContext;
use App\Services\Reception\ChannelReceptionPlanVersionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查询 Telegram 渠道回收站。
 */
class ListTelegramChannelTrashAction
{
    use AsAction;

    /**
     * 注入渠道部署版本状态解析器，让回收站列表也能展示历史部署状态。
     */
    public function __construct(
        private ChannelReceptionPlanVersionResolver $planVersionResolver,
    ) {}

    /**
     * 查询当前系统已删除的 Telegram 渠道列表。
     */
    public function handle(SystemContext $systemContext, int $page = 1, int $perPage = 12): ShowTelegramChannelTrashPagePropsData
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 24));

        $paginator = Channel::query()
            ->onlyTrashed()
            ->where('type', ChannelType::Telegram)
            ->with(['receptionPlan'])
            ->latest('deleted_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return new ShowTelegramChannelTrashPagePropsData(
            trashed_channel_list: $paginator->getCollection()
                ->map(fn (Channel $channel) => TelegramChannelData::fromModel(
                    $channel,
                    $this->planVersionResolver->resolveChannelStatus($systemContext, $channel),
                ))
                ->all(),
            trashed_channel_list_pagination: SimplePaginationData::fromPaginator($paginator),
        );
    }

    /**
     * 返回 Telegram 渠道回收站页面。
     */
    public function asController(Request $request): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        return Inertia::render('channel/telegram/Trash', $this->handle(
            systemContext: $systemContext,
            page: (int) $request->query('page', 1),
        )->toArray());
    }
}
