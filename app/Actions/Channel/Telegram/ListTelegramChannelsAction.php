<?php

namespace App\Actions\Channel\Telegram;

use App\Data\Channel\Telegram\ShowTelegramChannelListPagePropsData;
use App\Data\Channel\Telegram\TelegramChannelData;
use App\Data\SimplePaginationData;
use App\Data\WorkspaceUserContextData;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Workspace;
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
     * 查询当前工作区的 Telegram 渠道列表。
     */
    public function handle(Workspace $workspace, int $page = 1, int $perPage = 12): ShowTelegramChannelListPagePropsData
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 24));

        $paginator = Channel::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', ChannelType::Telegram)
            ->with(['receptionPlan'])
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return new ShowTelegramChannelListPagePropsData(
            channel_list: $paginator->getCollection()
                ->map(fn (Channel $channel) => TelegramChannelData::fromModel(
                    $channel,
                    $this->planVersionResolver->resolveChannelStatus($workspace, $channel),
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
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        return Inertia::render('channel/telegram/List', $this->handle(
            workspace: $workspace,
            page: (int) $request->query('page', 1),
        )->toArray());
    }
}
