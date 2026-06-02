<?php

namespace App\Actions\Channel\Telegram;

use App\Data\Channel\Telegram\ShowTelegramChannelListPagePropsData;
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
 * 查询 Telegram 渠道列表。
 */
class ListTelegramChannelsAction
{
    use AsAction;

    /**
     * 注入渠道部署版本状态解析器，让列表能直接显示当前部署是否可用。
     */
    public function __construct(
        private ChannelReceptionPlanVersionResolver $planVersionResolver,
    ) {}

    /**
     * 查询当前系统的 Telegram 渠道列表。
     */
    public function handle(SystemContext $systemContext, int $page = 1, int $perPage = 12): ShowTelegramChannelListPagePropsData
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 24));

        $paginator = Channel::query()
            ->where('type', ChannelType::Telegram)
            ->with(['receptionPlan'])
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return new ShowTelegramChannelListPagePropsData(
            channel_list: $paginator->getCollection()
                ->map(fn (Channel $channel) => TelegramChannelData::fromModel(
                    $channel,
                    $this->planVersionResolver->resolveChannelStatus($systemContext, $channel),
                ))
                ->all(),
            channel_list_pagination: SimplePaginationData::fromPaginator($paginator),
        );
    }

    /**
     * 返回 Telegram 渠道列表页面。
     */
    public function asController(Request $request): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        return Inertia::render('channel/telegram/List', $this->handle(
            systemContext: $systemContext,
            page: (int) $request->query('page', 1),
        )->toArray());
    }
}
