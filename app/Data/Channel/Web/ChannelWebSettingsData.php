<?php

namespace App\Data\Channel\Web;

use App\Enums\ReceptionLanguage;
use Spatie\LaravelData\Data;

/**
 * 渠道网站设置数据。
 * 由后端读取设置后传给 resources/js/pages/channel/web/List.vue、Show.vue 及 tabs/*，前端用它填充设置表单并展示当前配置。
 */
class ChannelWebSettingsData extends Data
{
    /**
     * 创建网站渠道设置数据。
     *
     * allowed_embed_hosts 为 null 时表示不限制嵌入域；
     * 为空数组也按"不限制"处理，保持白名单语义一致。
     * standalone_link_query 持久化聊天链接的附加 query 串（不含开头 `?`），仅允许追加在固定域名后。
     *
     * @param  list<string>|null  $allowed_embed_hosts
     * @param  list<WebChannelQueryParamMappingData>  $query_param_mappings
     */
    public function __construct(
        public ChannelWebWidgetSettingsData $widget = new ChannelWebWidgetSettingsData,
        public ChannelWebVisitorInterfaceSettingsData $visitor_interface = new ChannelWebVisitorInterfaceSettingsData(
            header: new ChannelWebHeaderData,
        ),
        public ChannelWebSuggestionsData $suggestions = new ChannelWebSuggestionsData,
        public ReceptionLanguage $default_visitor_locale = ReceptionLanguage::ChineseSimplified,
        public ?array $allowed_embed_hosts = null,
        public ?string $user_token_secret = null,
        public array $query_param_mappings = [],
        public ?string $standalone_link_query = null,
    ) {}

    /**
     * 创建带默认值的网站渠道设置。
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function defaults(array $overrides = []): self
    {
        return self::from(self::mergeSettings([
            'widget' => ChannelWebWidgetSettingsData::defaults()->toArray(),
            'visitor_interface' => ChannelWebVisitorInterfaceSettingsData::defaults()->toArray(),
            'suggestions' => ChannelWebSuggestionsData::defaults()->toArray(),
            'default_visitor_locale' => ReceptionLanguage::ChineseSimplified->value,
            'allowed_embed_hosts' => null,
            'user_token_secret' => null,
            'query_param_mappings' => [],
            'standalone_link_query' => null,
        ], $overrides));
    }

    /**
     * 基于当前设置合并局部覆盖值。
     *
     * @param  array<string, mixed>  $overrides
     */
    public function mergeWith(array $overrides): self
    {
        return self::defaults(self::mergeSettings($this->toArray(), $overrides));
    }

    /**
     * 递归合并设置；普通对象配置递归合并，列表字段整体替换。
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function mergeSettings(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && ! array_is_list($base[$key])
                && ! array_is_list($value)
            ) {
                $base[$key] = self::mergeSettings($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
