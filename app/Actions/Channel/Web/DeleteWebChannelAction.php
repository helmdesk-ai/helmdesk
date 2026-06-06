<?php

namespace App\Actions\Channel\Web;

use App\Enums\UserPermission;
use App\Models\Channel;
use App\Services\Channel\WebChannelResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 软删除系统中的网站渠道。
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
        Gate::authorize('user.permission', UserPermission::ChannelsDelete);

        $channelModel = $this->resolution->findSystemChannel($channel);

        $this->handle($channelModel);

        return redirect()->route('admin.manage.channels.web.index');
    }
}
