<?php

namespace App\Actions\Channel\Telegram;

use App\Data\Channel\Telegram\ChannelTelegramSettingsData;
use App\Data\Channel\Telegram\FormUpdateTelegramChannelTokenData;
use App\Data\SystemUserContextData;
use App\Enums\ChannelType;
use App\Exceptions\BusinessException;
use App\Exceptions\TelegramApiException;
use App\Models\Channel;
use App\Services\Telegram\TelegramBotApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 轮换 Telegram 渠道的 Bot Token：校验新 Token、回填机器人信息并重注册 webhook。
 */
class UpdateTelegramChannelTokenAction
{
    use AsAction;

    /**
     * 注入 Telegram API 客户端与 webhook 注册动作。
     */
    public function __construct(
        private readonly TelegramBotApi $api,
        private readonly RegisterTelegramWebhookAction $registerWebhook,
    ) {}

    /**
     * 用新 Token 重新校验并落库，随后重注册 webhook 保证 Telegram 侧一致。
     */
    public function handle(Channel $channel, FormUpdateTelegramChannelTokenData $data): void
    {
        try {
            $me = $this->api->getMe($data->bot_token);
        } catch (TelegramApiException $e) {
            throw new BusinessException(__('channel.telegram.errors.invalid_bot_token'));
        }

        $current = $channel->settings instanceof ChannelTelegramSettingsData
            ? $channel->settings
            : ChannelTelegramSettingsData::defaults();

        $settings = new ChannelTelegramSettingsData(
            webhook_secret: $current->webhook_secret,
            bot_username: is_string($me['username'] ?? null) ? $me['username'] : $current->bot_username,
            bot_id: is_int($me['id'] ?? null) ? $me['id'] : $current->bot_id,
            default_visitor_locale: $current->default_visitor_locale,
        );

        DB::transaction(fn () => $channel->update([
            'telegram_bot_token' => $data->bot_token,
            'settings' => $settings,
        ]));

        $this->registerWebhook->handle($channel->refresh());
    }

    /**
     * 接收 Token 轮换表单并返回详情页。
     */
    public function asController(Request $request, string $channel): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        $channelModel = Channel::query()
            ->where('type', ChannelType::Telegram)
            ->findOrFail($channel);

        $this->handle($channelModel, FormUpdateTelegramChannelTokenData::from($request));

        return redirect()->route('admin.manage.channels.telegram.show', [
            'channel' => $channelModel->id,
        ]);
    }
}
