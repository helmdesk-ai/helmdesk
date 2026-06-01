<?php

namespace App\Data\Reception;

use App\Enums\AutoMessageTranslationFailureMode;
use App\Enums\ReceptionPersonaTone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Data;

/**
 * 更新接待方案表单数据。
 * 由 Index.vue 单一保存按钮提交：基础信息、接待 / 任务模型、方案级知识库与 MCP 工具、
 * 全部服务场景同处一份载荷，service_scenarios 数组写入 reception_plans.capabilities JSON 列整批更新。
 */
class FormUpdateReceptionPlanData extends Data
{
    public function __construct(
        public string $name,
        public ?string $description,
        public string $persona_display_name,
        public string $persona_tone,
        public ?string $global_instructions,
        public string $reception_ai_model_id,
        public string $task_ai_model_id,
        /** @var array<string, mixed> */
        public array $strategy_config,
        /** @var array<string, array<string, mixed>> */
        public array $auto_messages_config,
        /** @var array<string, mixed> */
        public array $translation_config = [],
        /** @var list<array<string, mixed>> */
        public array $reception_model_candidates = [],
        /** @var list<array<string, mixed>> */
        public array $task_model_candidates = [],
        /** @var list<string> */
        public array $knowledge_base_ids = [],
        /** @var list<string> */
        public array $mcp_tool_ids = [],
        /** @var list<array<string, mixed>> */
        public array $service_scenarios = [],
    ) {}

    /**
     * 返回更新接待方案表单校验规则。
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
            'reception_ai_model_id' => ['required', 'string'],
            'reception_model_candidates' => ['array'],
            'reception_model_candidates.*.ai_model_id' => ['required', 'string'],
            'reception_model_candidates.*.priority' => ['required', 'integer', 'min:1', 'max:50'],
            'task_ai_model_id' => ['required', 'string'],
            'task_model_candidates' => ['array'],
            'task_model_candidates.*.ai_model_id' => ['required', 'string'],
            'task_model_candidates.*.priority' => ['required', 'integer', 'min:1', 'max:50'],
            'knowledge_base_ids' => ['array'],
            'knowledge_base_ids.*' => ['string', 'ulid'],
            'mcp_tool_ids' => ['array'],
            'mcp_tool_ids.*' => ['string', 'ulid'],
            'service_scenarios' => ['array'],
            'service_scenarios.*.name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'service_scenarios.*.description' => ['nullable', 'string', 'max:500'],
            'service_scenarios.*.instructions' => ['required', 'string', 'max:20000'],
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
            'translation_config.provider_id' => ['nullable', 'string', 'ulid', 'required_if:translation_config.enabled,true'],
        ], ReceptionStrategyConfigData::formRules());
    }

    /**
     * 校验接待策略字段的跨字段约束。
     * 服务场景名称按保存口径校验唯一性：首尾空白和大小写不参与区分。
     */
    public static function withValidator(Validator $validator): void
    {
        ReceptionStrategyConfigData::validateForm($validator);

        $validator->after(function (Validator $validator): void {
            $scenarios = $validator->getData()['service_scenarios'] ?? null;
            if (! is_array($scenarios)) {
                return;
            }

            $seen = [];
            foreach ($scenarios as $index => $scenario) {
                $rawName = is_array($scenario) && isset($scenario['name']) && is_string($scenario['name'])
                    ? $scenario['name']
                    : '';
                $normalized = mb_strtolower(trim($rawName));
                if ($normalized === '') {
                    continue;
                }
                if (isset($seen[$normalized])) {
                    $validator->errors()->add(
                        "service_scenarios.{$index}.name",
                        __('reception.messages.service_scenario_name_duplicated'),
                    );

                    continue;
                }
                $seen[$normalized] = true;
            }
        });
    }
}
