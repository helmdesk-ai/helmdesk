<?php

namespace App\Actions\Channel\Telegram;

use App\Data\Channel\Telegram\ChannelTelegramSettingsData;
use App\Enums\ChannelType;
use App\Enums\UserPermission;
use App\Exceptions\BusinessException;
use App\Exceptions\TelegramApiException;
use App\Models\Channel;
use App\Services\Telegram\TelegramBotApi;
use App\Services\Telegram\TelegramWebhookUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 向 Telegram 注册渠道 webhook，并把失败转换为可提示用户的业务异常。
 *
 * 创建渠道、轮换 Token、从回收站恢复时复用本 Action，保证 Telegram 侧 webhook 与本地配置一致。
 */
class RegisterTelegramWebhookAction
{
    use AsAction;

    /**
     * 注入 Telegram Bot API 客户端。
     */
    public function __construct(
        private readonly TelegramBotApi $api,
    ) {}

    /**
     * 注册 webhook：成功则无返回，失败抛 BusinessException 弹 toast。
     */
    public function handle(Channel $channel): void
    {
        $settings = $channel->settings instanceof ChannelTelegramSettingsData
            ? $channel->settings
            : ChannelTelegramSettingsData::defaults();

        try {
            $this->api->setWebhook(
                (string) $channel->telegram_bot_token,
                TelegramWebhookUrl::for($channel->code),
                $settings->webhook_secret,
            );
        } catch (TelegramApiException $e) {
            throw new BusinessException(__('channel.telegram.errors.webhook_registration_failed', [
                'reason' => $e->getMessage(),
            ]));
        }
    }

    /**
     * 接收手动「重新注册 webhook」请求，成功后回显 toast（结果不可见，需显式反馈）。
     */
    public function asController(Request $request, string $channel): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::ChannelsEdit);

        $channelModel = Channel::query()
            ->where('type', ChannelType::Telegram)
            ->findOrFail($channel);

        $this->handle($channelModel);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('channel.telegram.webhook_registered')]);

        return redirect()->route('admin.manage.channels.telegram.show', [
            'channel' => $channelModel->id,
        ]);
    }
}
