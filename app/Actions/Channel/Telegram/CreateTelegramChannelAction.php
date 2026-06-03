<?php

namespace App\Actions\Channel\Telegram;

use App\Actions\Reception\Plan\ResolveChannelReceptionPlanAction;
use App\Data\Channel\Telegram\ChannelTelegramSettingsData;
use App\Data\Channel\Telegram\FormCreateTelegramChannelData;
use App\Enums\ChannelType;
use App\Enums\UserPermission;
use App\Exceptions\BusinessException;
use App\Exceptions\TelegramApiException;
use App\Models\Channel;
use App\Services\Telegram\TelegramBotApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 创建 Telegram Bot 渠道：校验 Token、拉取机器人信息、加密落库并注册 webhook。
 */
class CreateTelegramChannelAction
{
    use AsAction;

    /**
     * 注入接待方案解析、Telegram API 客户端与 webhook 注册动作。
     */
    public function __construct(
        private readonly ResolveChannelReceptionPlanAction $resolveChannelReceptionPlan,
        private readonly TelegramBotApi $api,
        private readonly RegisterTelegramWebhookAction $registerWebhook,
    ) {}

    /**
     * 创建 Telegram 渠道并完成 webhook 注册。
     *
     * 流程严格「先校验后落库」：getMe 失败即拒绝（Token 非法），不写入任何数据；
     * webhook 注册失败则回滚已创建的渠道，避免留下半配置状态。
     */
    public function handle(FormCreateTelegramChannelData $data): Channel
    {
        $planId = $this->resolveChannelReceptionPlan->handle(
            $data->receptionPlanId(),
            requireUsable: true,
        );

        $botInfo = $this->fetchBotInfo($data->bot_token);

        $channel = Channel::query()->create([
            'type' => ChannelType::Telegram,
            'name' => $data->name,
            'description' => filled($data->description) ? $data->description : null,
            'reception_plan_id' => $planId,
            'telegram_bot_token' => $data->bot_token,
            'settings' => ChannelTelegramSettingsData::defaults([
                'webhook_secret' => Str::random(48),
                'bot_username' => $botInfo['username'],
                'bot_id' => $botInfo['id'],
            ]),
        ]);

        try {
            $this->registerWebhook->handle($channel);
        } catch (BusinessException $e) {
            // webhook 注册失败时回滚渠道，保证「要么完整可用，要么不存在」。
            $channel->forceDelete();

            throw $e;
        }

        return $channel;
    }

    /**
     * 调 getMe 校验 Token 并提取机器人 id / username。
     *
     * @return array{id: int, username: ?string}
     */
    private function fetchBotInfo(string $botToken): array
    {
        try {
            $me = $this->api->getMe($botToken);
        } catch (TelegramApiException $e) {
            throw new BusinessException(__('channel.telegram.errors.invalid_bot_token'));
        }

        return [
            'id' => is_int($me['id'] ?? null) ? $me['id'] : 0,
            'username' => is_string($me['username'] ?? null) ? $me['username'] : null,
        ];
    }

    /**
     * 接收创建 Telegram 渠道表单并跳转到渠道详情页。
     */
    public function asController(Request $request): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::ChannelsCreate);

        $channel = $this->handle(FormCreateTelegramChannelData::from($request));

        return redirect()->route('admin.manage.channels.telegram.show', [
            'channel' => $channel->id,
        ]);
    }
}
