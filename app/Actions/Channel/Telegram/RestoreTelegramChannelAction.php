<?php

namespace App\Actions\Channel\Telegram;

use App\Data\SystemUserContextData;
use App\Enums\ChannelType;
use App\Enums\UserPermission;
use App\Models\Channel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 从回收站恢复 Telegram 渠道，并重新向 Telegram 注册 webhook 以恢复入站。
 */
class RestoreTelegramChannelAction
{
    use AsAction;

    /**
     * 注入 webhook 注册动作。
     */
    public function __construct(
        private readonly RegisterTelegramWebhookAction $registerWebhook,
    ) {}

    /**
     * 恢复渠道并重注册 webhook；注册失败抛 BusinessException 弹 toast。
     */
    public function handle(Channel $channel): void
    {
        $channel->restore();

        $this->registerWebhook->handle($channel->refresh());
    }

    /**
     * 接收恢复请求并返回列表页。
     */
    public function asController(Request $request, string $channel): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::ChannelsEdit);

        $channelModel = Channel::query()
            ->withTrashed()
            ->where('type', ChannelType::Telegram)
            ->findOrFail($channel);

        $this->handle($channelModel);

        return redirect()->route('admin.manage.channels.telegram.index');
    }
}
