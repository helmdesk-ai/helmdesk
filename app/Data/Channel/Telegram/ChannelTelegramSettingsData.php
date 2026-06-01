<?php

namespace App\Data\Channel\Telegram;

use App\Enums\ReceptionLanguage;
use Spatie\LaravelData\Data;

/**
 * Telegram 渠道设置数据（不含 bot_token，token 由模型 encrypted 列单独承载）。
 * 由后端读取后传给 resources/js/pages/channel/telegram/Show.vue 展示当前配置；
 * webhook_secret 用于校验 Telegram 入站请求的 X-Telegram-Bot-Api-Secret-Token 头。
 */
class ChannelTelegramSettingsData extends Data
{
    /**
     * 创建 Telegram 渠道设置数据。
     *
     * webhook_secret 为入站校验密钥；bot_username / bot_id 由 getMe 拉取后回填用于展示；
     * default_visitor_locale 为无法从 Telegram 用户语言推断时的兜底语言。
     */
    public function __construct(
        public string $webhook_secret = '',
        public ?string $bot_username = null,
        public ?int $bot_id = null,
        public ReceptionLanguage $default_visitor_locale = ReceptionLanguage::ChineseSimplified,
    ) {}

    /**
     * 创建带默认值的 Telegram 渠道设置。
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function defaults(array $overrides = []): self
    {
        return self::from(array_merge([
            'webhook_secret' => '',
            'bot_username' => null,
            'bot_id' => null,
            'default_visitor_locale' => ReceptionLanguage::ChineseSimplified->value,
        ], $overrides));
    }
}
