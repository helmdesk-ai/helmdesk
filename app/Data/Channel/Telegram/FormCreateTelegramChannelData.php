<?php

namespace App\Data\Channel\Telegram;

use Spatie\LaravelData\Data;

/**
 * 创建 Telegram 渠道表单数据。
 * 来自 resources/js/pages/channel/telegram/Create.vue 的新增表单提交；
 * bot_token 为从 @BotFather 获取的凭证，后端会即时 getMe 校验后加密落库。
 */
class FormCreateTelegramChannelData extends Data
{
    /**
     * 创建 Telegram 渠道表单字段。
     *
     * reception_plan_id 必须指向工作区内已发布且可部署的接待方案。
     */
    public function __construct(
        public string $name,
        public string $bot_token,
        public string $reception_plan_id,
        public ?string $description = null,
    ) {}

    /**
     * 返回创建 Telegram 渠道表单校验规则。
     *
     * bot_token 形如 "<bot_id>:<secret>"，先在边界做格式校验，真正有效性由 getMe 决定。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'bot_token' => ['required', 'string', 'max:200', 'regex:/^\d+:[A-Za-z0-9_-]{20,}$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reception_plan_id' => ['required', 'string', 'ulid'],
        ];
    }

    /**
     * 表单提交的接待方案 ID。
     */
    public function receptionPlanId(): string
    {
        return $this->reception_plan_id;
    }
}
