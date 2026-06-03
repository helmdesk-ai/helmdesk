<?php

namespace App\Actions\Channel\Web;

use App\Data\Channel\Web\ShowWebChannelListPagePropsData;
use App\Data\Channel\Web\WebChannelData;
use App\Data\SimplePaginationData;
use App\Data\SystemUserContextData;
use App\Enums\ChannelType;
use App\Enums\UserPermission;
use App\Models\Channel;
use App\Models\SystemContext;
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
     * 查询当前系统的网站渠道列表。
     */
    public function handle(SystemContext $systemContext, int $page = 1, int $perPage = 12): ShowWebChannelListPagePropsData
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
                    $this->planVersionResolver->resolveChannelStatus($systemContext, $channel),
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
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::ChannelsView);

        return Inertia::render('channel/web/List', $this->handle(
            systemContext: $systemContext,
            page: (int) $request->query('page', 1),
        )->toArray());
    }
}
