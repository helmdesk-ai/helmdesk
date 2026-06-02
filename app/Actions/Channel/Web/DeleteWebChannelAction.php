<?php

namespace App\Actions\Channel\Web;

use App\Data\WorkspaceUserContextData;
use App\Models\Channel;
use App\Services\Channel\WebChannelResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 软删除工作区中的网站渠道。
 */
class DeleteWebChannelAction
{
    use AsAction;

    /**
     * 注入渠道解析服务。
     */
    public function __construct(
        private readonly WebChannelResolutionService $resolution,
    ) {}

    public function handle(Channel $channel): void
    {
        $channel->delete();
    }

    public function asController(Request $request, string $channel): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $channelModel = $this->resolution->findWorkspaceChannel($workspace, $channel);

        $this->handle($channelModel);

        return redirect()->route('workspace.manage.channels.web.index');
    }
}
