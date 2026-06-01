<?php

namespace App\Data\Reception\ServiceScenario;

use Spatie\LaravelData\Data;

/**
 * 服务场景预置模板数据。
 * 由 ShowReceptionPlanIndexPageAction 一次性下发给 Index.vue，
 * 在新建服务场景 Dialog 的"使用模板"入口里供前端预填表单字段。
 */
class ServiceScenarioTemplateData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public string $description,
        public string $preview_instructions,
        public string $instructions,
    ) {}

    /**
     * 从模板常量数组构造下发数据。
     * preview_instructions 截断到前 200 字便于卡片展示；instructions 保留全量内容。
     *
     * @param  array<string, mixed>  $template
     */
    public static function fromArray(array $template): self
    {
        $instructions = isset($template['instructions']) && is_string($template['instructions'])
            ? $template['instructions']
            : '';

        return new self(
            code: (string) ($template['code'] ?? ''),
            name: (string) ($template['name'] ?? ''),
            description: (string) ($template['description'] ?? ''),
            preview_instructions: mb_substr($instructions, 0, 200),
            instructions: $instructions,
        );
    }
}
