<?php

namespace App\Data\Reception\Plan;

use App\Enums\AutoMessageTranslationFailureMode;
use App\Enums\ReceptionPersonaTone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Data;

/**
 * 创建接待方案表单数据。
 * 来自 resources/js/pages/reception/plans/Create.vue 的表单提交，后端用它做校验并写入 reception_plans 草稿。
 * 草稿字段保持扁平：基础信息、人设指引分别提交为顶层字段；模型不再由方案选择，运行时按用途从全局池取用。
 */
class FormCreateReceptionPlanData extends Data
{
    public function __construct(
        public string $name,
        public ?string $description,
        public string $persona_display_name,
        public string $persona_tone,
        public ?string $global_instructions,
        /** @var array<string, mixed> */
        public array $strategy_config,
        /** @var array<string, array<string, mixed>> */
        public array $auto_messages_config,
        /** @var array<string, mixed> */
        public array $translation_config = [],
    ) {}

    /**
     * 返回创建接待方案表单校验规则。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $toneValues = array_map(static fn (ReceptionPersonaTone $t) => $t->value, ReceptionPersonaTone::cases());
        $translationFailureValues = array_map(
            static fn (AutoMessageTranslationFailureMode $mode): string => $mode->value,
            AutoMessageTranslationFailureMode::cases(),
        );

        return array_merge([
            'name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'description' => ['nullable', 'string', 'max:500'],
            'persona_display_name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'persona_tone' => ['required', 'string', Rule::in($toneValues)],
            'global_instructions' => ['nullable', 'string', 'max:20000'],
            'auto_messages_config' => ['required', 'array'],
            'auto_messages_config.ai_welcome.enabled' => ['required', 'boolean'],
            'auto_messages_config.ai_welcome.message' => ['present', 'nullable', 'string', 'max:1000'],
            'auto_messages_config.teammate_joined.enabled' => ['required', 'boolean'],
            'auto_messages_config.teammate_joined.message' => ['present', 'nullable', 'string', 'max:1000'],
            'auto_messages_config.teammate_transferred.enabled' => ['required', 'boolean'],
            'auto_messages_config.teammate_transferred.message' => ['present', 'nullable', 'string', 'max:1000'],
            'translation_config' => ['sometimes', 'array'],
            'translation_config.enabled' => ['required_with:translation_config', 'boolean'],
            'translation_config.failure_mode' => ['required_with:translation_config', 'string', Rule::in($translationFailureValues)],
        ], ReceptionStrategyConfigData::formRules());
    }

    /**
     * 校验接待策略字段的跨字段约束。
     */
    public static function withValidator(Validator $validator): void
    {
        ReceptionStrategyConfigData::validateForm($validator);
    }
}
