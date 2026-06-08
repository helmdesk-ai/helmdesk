<?php

namespace App\Data\AiModel;

use App\Enums\AiModelPurpose;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 「AI 模型管理」新增模型表单数据（来源：resources/js/pages/systemSettings/aiModels/ModelForm.vue）。
 *
 * 一行=一个模型+一个用途：选供应商 + 用途 + model_id + 名称；type 由用途派生（不单独提交），无维度。
 */
class FormCreateAiModelData extends Data
{
    public function __construct(
        public string $ai_provider_id,
        public AiModelPurpose $purpose,
        public string $model_id,
        public string $name,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'ai_provider_id' => ['required', 'string', 'exists:ai_providers,id'],
            'purpose' => ['required', Rule::enum(AiModelPurpose::class)],
            'model_id' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:128'],
        ];
    }
}
