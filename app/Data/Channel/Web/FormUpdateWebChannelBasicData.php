<?php

namespace App\Data\Channel\Web;

use App\Enums\ReceptionLanguage;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 更新网站渠道基础表单数据。
 * 来自 resources/js/pages/channel/web/List.vue、Show.vue 及 tabs/* 的编辑表单提交，后端用它校验并保存网站渠道配置。
 */
class FormUpdateWebChannelBasicData extends Data
{
    /**
     * 更新网站渠道基础信息表单字段。
     */
    public function __construct(
        public string $name,
        public string $reception_plan_id,
        public ?string $description = null,
        public ?ReceptionLanguage $default_visitor_locale = null,
    ) {}

    /**
     * 返回基础信息表单校验规则。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reception_plan_id' => ['required', 'string', 'ulid'],
            'default_visitor_locale' => ['nullable', Rule::enum(ReceptionLanguage::class)],
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
