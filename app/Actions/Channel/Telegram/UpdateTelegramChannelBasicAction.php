<?php

namespace App\Actions\Channel\Telegram;

use App\Actions\Reception\Plan\ResolveChannelReceptionPlanAction;
use App\Data\Channel\Telegram\ChannelTelegramSettingsData;
use App\Data\Channel\Telegram\FormUpdateTelegramChannelBasicData;
use App\Enums\ChannelType;
use App\Enums\UserPermission;
use App\Models\Channel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新 Telegram 渠道的基本信息、绑定的接待方案与默认访客语言。
 */
class UpdateTelegramChannelBasicAction
{
    use AsAction;

    /**
     * 注入渠道接待方案解析器。
     */
    public function __construct(
        private readonly ResolveChannelReceptionPlanAction $resolveChannelReceptionPlan,
    ) {}

    /**
     * 保存 Telegram 渠道基本信息与接待方案引用。
     */
    public function handle(Channel $channel, FormUpdateTelegramChannelBasicData $data): void
    {
        $submittedPlanId = $data->receptionPlanId();
        $requireUsable = $submittedPlanId !== $channel->reception_plan_id;
        $planId = $this->resolveChannelReceptionPlan->handle(
            $submittedPlanId,
            requireUsable: $requireUsable,
        );

        $current = $channel->settings instanceof ChannelTelegramSettingsData
            ? $channel->settings
            : ChannelTelegramSettingsData::defaults();

        $settings = new ChannelTelegramSettingsData(
            webhook_secret: $current->webhook_secret,
            bot_username: $current->bot_username,
            bot_id: $current->bot_id,
            default_visitor_locale: $data->default_visitor_locale,
        );

        DB::transaction(fn () => $channel->update([
            'name' => $data->name,
            'description' => filled($data->description) ? $data->description : null,
            'reception_plan_id' => $planId,
            'settings' => $settings,
        ]));
    }

    /**
     * 接收基本信息表单并返回详情页。
     */
    public function asController(Request $request, string $channel): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::ChannelsEdit);

        $channelModel = Channel::query()
            ->where('type', ChannelType::Telegram)
            ->findOrFail($channel);

        $this->handle($channelModel, FormUpdateTelegramChannelBasicData::from($request));

        return redirect()->route('admin.manage.channels.telegram.show', [
            'channel' => $channelModel->id,
        ]);
    }
}
