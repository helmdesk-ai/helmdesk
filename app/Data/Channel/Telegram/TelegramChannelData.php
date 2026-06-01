<?php

namespace App\Data\Channel\Telegram;

use App\Data\AiRuntime\ModelSelectionStatusData;
use App\Enums\ReceptionLanguage;
use App\Models\Channel;
use App\Services\Telegram\TelegramWebhookUrl;
use Spatie\LaravelData\Data;

/**
 * Telegram 渠道前端展示数据。
 * 由后端组装后传给 resources/js/pages/channel/telegram/List.vue、Show.vue，用于列表与详情展示。
 * 不包含 bot_token：高敏凭证不下发前端，仅以「已配置」布尔暴露状态。
 */
class TelegramChannelData extends Data
{
    /**
     * 创建 Telegram 渠道前端展示数据。
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $code,
        public ?string $bot_username,
        public bool $bot_token_configured,
        public string $webhook_url,
        public bool $webhook_active,
        public ?string $reception_plan_id,
        public ?string $reception_plan_name,
        public ?ModelSelectionStatusData $reception_plan_status_detail,
        public ReceptionLanguage $default_visitor_locale,
        public ?string $updated_at,
        public ?string $deleted_at,
    ) {}

    /**
     * 从渠道模型组装 Telegram 渠道前端展示数据。
     *
     * webhook_active 以渠道是否被软删除（暂停）近似：创建/恢复时强制注册成功，暂停时删除 webhook。
     */
    public static function fromModel(Channel $channel, ?ModelSelectionStatusData $planStatus = null): self
    {
        $settings = $channel->settings instanceof ChannelTelegramSettingsData
            ? $channel->settings
            : ChannelTelegramSettingsData::defaults();

        $plan = $channel->relationLoaded('receptionPlan')
            ? $channel->receptionPlan
            : $channel->receptionPlan()->first();

        return new self(
            id: (string) $channel->id,
            name: $channel->name,
            description: $channel->description,
            code: $channel->code,
            bot_username: $settings->bot_username,
            bot_token_configured: filled($channel->telegram_bot_token),
            webhook_url: TelegramWebhookUrl::for($channel->code),
            webhook_active: ! $channel->trashed(),
            reception_plan_id: filled($channel->reception_plan_id) ? (string) $channel->reception_plan_id : null,
            reception_plan_name: filled($plan?->name) ? (string) $plan->name : null,
            reception_plan_status_detail: $planStatus,
            default_visitor_locale: $settings->default_visitor_locale,
            updated_at: $channel->updated_at?->toIso8601String(),
            deleted_at: $channel->deleted_at?->toIso8601String(),
        );
    }
}
