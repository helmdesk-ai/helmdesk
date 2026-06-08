<?php

namespace Database\Factories;

use App\Enums\AiModelPurpose;
use App\Enums\AiModelType;
use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 全局 AI 模型工厂（系统级，跨工作区共享，一行=一个模型+一个用途）。
 *
 * 默认是一个启用中、用途为接待智能体（reception_chat）的 LLM 模型，自动挂到一个新建的全局供应商下。
 *
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    /**
     * @var class-string<AiModel>
     */
    protected $model = AiModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_provider_id' => fn (): string => AiProviderFactory::new()->create()->id,
            'model_id' => 'model-'.Str::lower(Str::random(8)),
            'name' => 'Model '.Str::upper(Str::random(6)),
            'type' => AiModelType::Llm->value,
            'purpose' => AiModelPurpose::ReceptionChat->value,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * 指定所属供应商。
     */
    public function forProvider(AiProvider $provider): self
    {
        return $this->state(fn (): array => ['ai_provider_id' => $provider->id]);
    }

    /**
     * 设定用途（type 随之取该用途的能力类型）。
     */
    public function purpose(AiModelPurpose $purpose): self
    {
        return $this->state(fn (): array => [
            'purpose' => $purpose->value,
            'type' => $purpose->modelType()->value,
        ]);
    }

    /**
     * 切换为 embedding 模型（用途=向量检索）。
     */
    public function embedding(): self
    {
        return $this->state(fn (): array => [
            'type' => AiModelType::Embedding->value,
            'purpose' => AiModelPurpose::Embedding->value,
        ]);
    }

    /**
     * 切换为 rerank 模型（用途=重排序）。
     */
    public function rerank(): self
    {
        return $this->state(fn (): array => [
            'type' => AiModelType::Rerank->value,
            'purpose' => AiModelPurpose::Rerank->value,
        ]);
    }

    /**
     * 标记为停用（不参与运行时取用）。
     */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
