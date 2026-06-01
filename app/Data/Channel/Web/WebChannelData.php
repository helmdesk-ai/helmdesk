<?php

namespace App\Data\Channel\Web;

use App\Data\AiRuntime\ModelSelectionStatusData;
use App\Enums\ReceptionLanguage;
use App\Models\Channel;
use Spatie\LaravelData\Data;

/**
 * 网站渠道数据。
 * 由后端组装后传给 resources/js/pages/channel/web/List.vue、Show.vue 及 tabs/*，用于页面展示、抽屉详情或局部交互状态。
 */
class WebChannelData extends Data
{
    /**
     * 创建网站渠道前端展示数据。
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $code,
        public ?string $reception_plan_id,
        public ?string $reception_plan_name,
        public ?ModelSelectionStatusData $reception_plan_status_detail,
        public WebChannelVisitorInterfaceData $visitor_interface,
        public ChannelWebSuggestionsData $suggestions,
        public WebChannelWidgetData $widget,
        public string $standalone_url,
        public ?string $standalone_link_query,
        public string $standalone_chat_link,
        public string $widget_snippet,
        /** @var list<string> */
        public array $allowed_embed_hosts,
        public ?string $user_token_secret_masked,
        public ?string $user_token_secret,
        /** @var list<WebChannelQueryParamMappingData> */
        public array $query_param_mappings,
        public ?string $first_embed_host,
        public ?string $first_embed_at,
        public ?string $last_embed_host,
        public ?string $last_embed_at,
        public ?string $updated_at,
        public ?string $deleted_at,
        public ReceptionLanguage $default_visitor_locale,
    ) {}

    /**
     * 从渠道模型组装网站渠道前端展示数据。
     *
     * @param  array<string, string|null>|null  $widgetEntryIconUrls
     */
    public static function fromModel(Channel $channel, ?ModelSelectionStatusData $planStatus = null, ?array $widgetEntryIconUrls = null): self
    {
        $settings = $channel->settings instanceof ChannelWebSettingsData
            ? $channel->settings
            : ChannelWebSettingsData::defaults();
        $embed = WebChannelEmbedData::fromChannel($channel);

        $plan = $channel->relationLoaded('receptionPlan')
            ? $channel->receptionPlan
            : $channel->receptionPlan()->first();

        $standaloneLinkQuery = self::normalizeLinkQuery($settings->standalone_link_query);
        $standaloneChatLink = $standaloneLinkQuery === null
            ? $embed->standalone_url
            : $embed->standalone_url.'?'.$standaloneLinkQuery;

        return new self(
            id: (string) $channel->id,
            name: $channel->name,
            description: $channel->description,
            code: $channel->code,
            reception_plan_id: filled($channel->reception_plan_id) ? (string) $channel->reception_plan_id : null,
            reception_plan_name: filled($plan?->name) ? (string) $plan->name : null,
            reception_plan_status_detail: $planStatus,
            visitor_interface: WebChannelVisitorInterfaceData::fromModel($channel),
            suggestions: $settings->suggestions,
            widget: WebChannelWidgetData::fromModel($channel, $widgetEntryIconUrls),
            standalone_url: $embed->standalone_url,
            standalone_link_query: $standaloneLinkQuery,
            standalone_chat_link: $standaloneChatLink,
            widget_snippet: $embed->widget_snippet,
            allowed_embed_hosts: array_values(array_filter(
                $settings->allowed_embed_hosts ?? [],
                static fn ($host): bool => is_string($host) && $host !== '',
            )),
            user_token_secret_masked: self::maskUserTokenSecret($settings->user_token_secret),
            user_token_secret: filled($settings->user_token_secret) ? (string) $settings->user_token_secret : null,
            query_param_mappings: array_map(
                static fn ($mapping): WebChannelQueryParamMappingData => $mapping instanceof WebChannelQueryParamMappingData
                    ? $mapping
                    : WebChannelQueryParamMappingData::from($mapping),
                $settings->query_param_mappings,
            ),
            first_embed_host: $channel->first_embed_host,
            first_embed_at: $channel->first_embed_at?->toIso8601String(),
            last_embed_host: $channel->last_embed_host,
            last_embed_at: $channel->last_embed_at?->toIso8601String(),
            updated_at: $channel->updated_at?->toIso8601String(),
            deleted_at: $channel->deleted_at?->toIso8601String(),
            default_visitor_locale: $settings->default_visitor_locale,
        );
    }

    /**
     * 生成管理端可展示的签名密钥脱敏文本。
     */
    private static function maskUserTokenSecret(?string $secret): ?string
    {
        $value = trim((string) $secret);
        if ($value === '') {
            return null;
        }

        return substr($value, 0, 8).'********'.substr($value, -8);
    }

    /**
     * 规范化聊天链接附加 query：去除首尾空白与起始问号；空串视为未设置。
     */
    private static function normalizeLinkQuery(?string $query): ?string
    {
        $value = ltrim(trim((string) $query), '?');

        return $value === '' ? null : $value;
    }
}
