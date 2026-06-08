<?php

namespace App\Data\AiModel;

use Spatie\LaravelData\Data;

/**
 * 「AI 模型管理」更新模型表单数据（来源：resources/js/pages/systemSettings/aiModels/ModelForm.vue）。
 *
 * 仅改名称与启用状态；供应商 / 用途 / model_id 创建后不可变。
 */
class FormUpdateAiModelData extends Data
{
    public function __construct(
        public string $name,
        public bool $is_active = true,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128'],
            'is_active' => ['boolean'],
        ];
    }
}
