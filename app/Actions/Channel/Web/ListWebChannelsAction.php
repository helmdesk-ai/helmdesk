<?php

namespace App\Actions\Channel\Web;

use App\Data\Channel\Web\ShowWebChannelListPagePropsData;
use App\Data\Channel\Web\WebChannelData;
use App\Data\SimplePaginationData;
use App\Data\WorkspaceUserContextData;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Workspace;
use App\Services\Channel\WebChannelWidgetEntryIconResolver;
use App\Services\Reception\ChannelReceptionPlanVersionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查询网站渠道列表。
 */
class ListWebChannelsAction
{
    use AsAction;

    /**
     * 注入渠道部署版本状态解析器和入口图标地址解析器。
     */
    public function __construct(
        private ChannelReceptionPlanVersionResolver $planVersionResolver,
        private WebChannelWidgetEntryIconResolver $entryIconResolver,
    ) {}

    /**
     * 查询当前工作区的网站渠道列表。
     */
    public function handle(Workspace $workspace, int $page = 1, int $perPage = 12): ShowWebChannelListPagePropsData
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 24));

        $paginator = Channel::query()
            ->where('type', ChannelType::Web)
            ->with(['receptionPlan'])
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $channels = $paginator->getCollection();
        $entryIconUrls = $this->entryIconResolver->urlsForChannels($channels);

        return new ShowWebChannelListPagePropsData(
            channel_list: $channels
                ->map(fn (Channel $channel) => WebChannelData::fromModel(
                    $channel,
                    $this->planVersionResolver->resolveChannelStatus($workspace, $channel),
                    $entryIconUrls,
                ))
                ->all(),
            channel_list_pagination: SimplePaginationData::fromPaginator($paginator),
        );
    }

    /**
     * 返回网站渠道列表页面。
     */
    public function asController(Request $request): Response
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        return Inertia::render('channel/web/List', $this->handle(
            workspace: $workspace,
            page: (int) $request->query('page', 1),
        )->toArray());
    }
}
