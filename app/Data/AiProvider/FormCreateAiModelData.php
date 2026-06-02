<?php

namespace App\Data\AiProvider;

use App\Enums\AiModelType;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 创建AI模型表单数据。
 * 来自 resources/js/pages/systemSettings/aiProviders/ModelFormDialog.vue 的提交，
 * 后端用它校验并写入系统供应商下的模型配置。
 */
class FormCreateAiModelData extends Data
{
    public function __construct(
        public string $model_id,
        public string $name,
        public string $type,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'model_id' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', 'string', Rule::enum(AiModelType::class)],
        ];
    }
}
