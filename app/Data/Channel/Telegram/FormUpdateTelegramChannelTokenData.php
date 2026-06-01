<?php

namespace App\Data\Channel\Telegram;

use Spatie\LaravelData\Data;

/**
 * 轮换 Telegram 渠道 Bot Token 表单数据。
 * 来自 resources/js/pages/channel/telegram/Show.vue 的 Token 轮换操作；
 * 后端会用新 Token 重新 getMe 校验并重注册 webhook。
 */
class FormUpdateTelegramChannelTokenData extends Data
{
    /**
     * Token 轮换表单字段。
     */
    public function __construct(
        public string $bot_token,
    ) {}

    /**
     * 返回 Token 轮换表单校验规则。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'bot_token' => ['required', 'string', 'max:200', 'regex:/^\d+:[A-Za-z0-9_-]{20,}$/'],
        ];
    }
}
