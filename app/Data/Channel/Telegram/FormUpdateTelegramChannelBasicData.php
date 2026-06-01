<?php

namespace App\Data\Channel\Telegram;

use App\Enums\ReceptionLanguage;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 更新 Telegram 渠道基本信息表单数据。
 * 来自 resources/js/pages/channel/telegram/Show.vue 的基本信息表单，更新名称、描述、
 * 部署的接待方案与默认访客语言；不涉及 bot_token 轮换（见 FormUpdateTelegramChannelTokenData）。
 */
class FormUpdateTelegramChannelBasicData extends Data
{
    /**
     * 更新 Telegram 渠道基本信息表单字段。
     */
    public function __construct(
        public string $name,
        public string $reception_plan_id,
        public ReceptionLanguage $default_visitor_locale,
        public ?string $description = null,
    ) {}

    /**
     * 返回更新 Telegram 渠道基本信息表单校验规则。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reception_plan_id' => ['required', 'string', 'ulid'],
            'default_visitor_locale' => ['required', Rule::enum(ReceptionLanguage::class)],
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
