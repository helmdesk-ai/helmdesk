<?php

namespace App\Actions\Channel\Web;

use App\Data\WorkspaceUserContextData;
use App\Enums\ChannelType;
use App\Models\Channel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 恢复已删除的网站渠道。
 */
class RestoreWebChannelAction
{
    use AsAction;

    /**
     * 恢复指定的软删除网站渠道。
     */
    public function handle(Channel $channel): void
    {
        $channel->restore();
    }

    /**
     * 接收渠道恢复请求并返回上一页。
     */
    public function asController(Request $request, string $slug, string $channel): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $channelModel = Channel::query()
            ->onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->where('type', ChannelType::Web)
            ->findOrFail($channel);

        $this->handle($channelModel);

        return back();
    }
}
