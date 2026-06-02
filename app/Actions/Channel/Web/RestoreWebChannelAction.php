<?php

namespace App\Actions\Channel\Web;

use App\Data\SystemUserContextData;
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
    public function asController(Request $request, string $channel): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        $channelModel = Channel::query()
            ->onlyTrashed()
            ->where('type', ChannelType::Web)
            ->findOrFail($channel);

        $this->handle($channelModel);

        return back();
    }
}
