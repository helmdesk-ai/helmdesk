<?php

namespace App\Services\AiRuntime;

use App\Data\AiRuntime\AiModelOptionData;
use App\Data\AiRuntime\ModelSelectionStatusData;
use App\Enums\AiModelType;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Settings\KnowledgeSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * 解析当前系统内的可用模型和模型引用状态。
 */
class AiModelResolver
{
    /**
     * 列出可选的 LLM 模型。
     *
     * @return AiModelOptionData[]
     */
    public function getActiveLlmModelOptions(): array
    {
        return $this->activeSystemModelsQuery(AiModelType::Llm)
            ->get()
            ->map(fn (AiModel $model) => AiModelOptionData::fromModel($model))
            ->all();
    }

    /**
     * 判断模型选择是否仍然可用。
     */
    public function resolveModelStatus(?string $modelId): ModelSelectionStatusData
    {
        if ($modelId === null) {
            return new ModelSelectionStatusData(
                id: null,
                label: null,
                isValid: false,
                reason: 'missing_after_delete',
                reason_label: __('ai_runtime.model_status.missing_after_delete'),
            );
        }

        $model = AiModel::query()
            ->with('provider')
            ->find($modelId);

        if ($model === null) {
            return new ModelSelectionStatusData(
                id: $modelId,
                label: null,
                isValid: false,
                reason: 'deleted',
                reason_label: __('ai_runtime.model_status.deleted'),
            );
        }

        if (! $model->is_active) {
            return new ModelSelectionStatusData(
                id: $model->id,
                label: $this->formatModelLabel($model),
                isValid: false,
                reason: 'model_inactive',
                reason_label: __('ai_runtime.model_status.model_inactive'),
            );
        }

        return new ModelSelectionStatusData(
            id: $model->id,
            label: $this->formatModelLabel($model),
            isValid: true,
        );
    }

    /**
     * 检查模型是否是启用中的 LLM。
     */
    public function isValidActiveLlmModel(?string $modelId): bool
    {
        if ($modelId === null) {
            return false;
        }

        return $this->activeSystemModelsQuery(AiModelType::Llm)
            ->whereKey($modelId)
            ->exists();
    }

    /**
     * 模型存在且可用时返回，否则抛出业务异常并附带传入的多语言消息。
     * 主要给接待方案保存/发布等需要在多个字段上做同样可用性校验的场景使用。
     */
    public function assertActiveLlmModelOrFail(?string $modelId, string $messageKey): void
    {
        if (! $this->isValidActiveLlmModel($modelId)) {
            throw new BusinessException(__($messageKey));
        }
    }

    /**
     * 检查模型是否被系统内任一接待方案（草稿）或已发布版本引用。
     *
     * Plan 草稿引用走 reception_plans.reception_config / task_config 里的 ai_model_id；
     * Version 引用走 reception_plan_versions.compiled_config 的同名 JSON 路径——
     * 这样删除模型前能精确给出 Plan 草稿或线上版本"还在用"。
     */
    public function isModelReferencedByReceptionPlans(string $modelId): bool
    {
        return $this->isModelReferencedByPlanDrafts($modelId)
            || $this->isModelReferencedByPublishedPlanVersions($modelId);
    }

    /**
     * 检查模型是否被知识库统一配置引用。
     */
    public function isModelReferencedByKnowledgeBases(string $modelId): bool
    {
        /** @var KnowledgeSettings $settings */
        $settings = app(KnowledgeSettings::class);
        $settings->refresh();

        return in_array($modelId, [
            $settings->embedding_model_id,
            $settings->rerank_model_id,
            $settings->summary_model_id,
        ], true);
    }

    /**
     * 检查供应商下任一模型是否被接待方案草稿或已发布版本引用。
     */
    public function isProviderReferencedByReceptionPlans(AiProvider $provider): bool
    {
        $modelIds = $provider->models()->pluck('id');

        if ($modelIds->isEmpty()) {
            return false;
        }

        foreach ($modelIds as $modelId) {
            if ($this->isModelReferencedByReceptionPlans((string) $modelId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 校验版本内的接待 / 任务候选模型是否都仍可用。
     *
     * @param  array<string, mixed>  $compiled
     */
    public function hasUsableModels(array $compiled): bool
    {
        $receptionConfig = $compiled['reception_config'] ?? [];
        $taskConfig = $compiled['task_config'] ?? [];
        $modelIds = array_values(array_unique(array_merge(
            $this->collectModelIds($receptionConfig),
            $this->collectModelIds($taskConfig),
        )));

        if ($modelIds === []) {
            return false;
        }

        foreach ($modelIds as $modelId) {
            if (! $this->resolveModelStatus($modelId)->isValid) {
                return false;
            }
        }

        return true;
    }

    /**
     * 从模型配置 JSON 中取出默认模型 ID 与候选模型 ID 列表。
     *
     * @param  array<string, mixed>  $modelConfig
     * @return list<string>
     */
    public function collectModelIds(array $modelConfig): array
    {
        $ids = [];
        $defaultModelId = $modelConfig['default_model']['ai_model_id'] ?? null;
        if (is_string($defaultModelId) && filled($defaultModelId)) {
            $ids[$defaultModelId] = true;
        }

        $candidates = $modelConfig['model_candidates'] ?? [];

        foreach ($candidates as $candidate) {
            $modelId = $candidate['ai_model_id'] ?? null;
            if (is_string($modelId) && filled($modelId)) {
                $ids[$modelId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * 接待方案草稿是否引用了该模型；扫 reception_plans 表两个 JSON 配置块。
     */
    private function isModelReferencedByPlanDrafts(string $modelId): bool
    {
        if (ReceptionPlan::query()
            ->where(function (Builder $query) use ($modelId): void {
                $query
                    ->where('reception_config->default_model->ai_model_id', $modelId)
                    ->orWhere('task_config->default_model->ai_model_id', $modelId);
            })
            ->exists()) {
            return true;
        }

        return ReceptionPlan::query()
            ->get(['reception_config', 'task_config'])
            ->contains(fn (ReceptionPlan $plan): bool => $this->modelCandidatesReferenceModel($plan->reception_config, $modelId)
                || $this->modelCandidatesReferenceModel($plan->task_config, $modelId));
    }

    /**
     * 接待方案版本是否引用了该模型；扫 reception_plan_versions.compiled_config 的两个 JSON 路径。
     */
    private function isModelReferencedByPublishedPlanVersions(string $modelId): bool
    {
        if (ReceptionPlanVersion::query()
            ->where(function (Builder $query) use ($modelId): void {
                $query
                    ->where('compiled_config->reception_config->default_model->ai_model_id', $modelId)
                    ->orWhere('compiled_config->task_config->default_model->ai_model_id', $modelId);
            })
            ->exists()) {
            return true;
        }

        return ReceptionPlanVersion::query()
            ->get(['compiled_config'])
            ->contains(function (ReceptionPlanVersion $version) use ($modelId): bool {
                $compiled = is_array($version->compiled_config) ? $version->compiled_config : [];
                $receptionConfig = isset($compiled['reception_config']) && is_array($compiled['reception_config'])
                    ? $compiled['reception_config']
                    : [];
                $taskConfig = isset($compiled['task_config']) && is_array($compiled['task_config'])
                    ? $compiled['task_config']
                    : [];

                return $this->modelCandidatesReferenceModel($receptionConfig, $modelId)
                    || $this->modelCandidatesReferenceModel($taskConfig, $modelId);
            });
    }

    /**
     * 检查模型配置里的候选模型列表是否引用指定模型。
     *
     * @param  array<string, mixed>|null  $modelConfig
     */
    private function modelCandidatesReferenceModel(?array $modelConfig, string $modelId): bool
    {
        $candidates = isset($modelConfig['model_candidates']) && is_array($modelConfig['model_candidates'])
            ? $modelConfig['model_candidates']
            : [];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && ($candidate['ai_model_id'] ?? null) === $modelId) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查供应商模型是否被知识库统一配置引用。
     */
    public function isProviderReferencedByKnowledgeBases(AiProvider $provider): bool
    {
        $modelIds = $provider->models()->pluck('id');

        if ($modelIds->isEmpty()) {
            return false;
        }

        /** @var KnowledgeSettings $settings */
        $settings = app(KnowledgeSettings::class);
        $settings->refresh();

        return collect([
            $settings->embedding_model_id,
            $settings->rerank_model_id,
            $settings->summary_model_id,
        ])->filter()->intersect($modelIds)->isNotEmpty();
    }

    /**
     * 生成模型选择框里的完整标签。
     */
    private function formatModelLabel(AiModel $model): string
    {
        return $model->provider->name.' / '.$model->name.' ('.$model->model_id.')';
    }

    /**
     * 校验并取得启用供应商下的启用模型。
     */
    public function resolveActiveKnowledgeBaseModel(string $modelId, AiModelType $type, string $field): AiModel
    {
        $model = $this->activeSystemModelsQuery($type)
            ->whereKey($modelId)
            ->first();

        if ($model !== null) {
            return $model;
        }

        throw ValidationException::withMessages([
            $field => match ($type) {
                AiModelType::Embedding => __('knowledge_base.messages.invalid_embedding_model'),
                AiModelType::Rerank => __('knowledge_base.messages.invalid_rerank_model'),
                AiModelType::Llm => __('knowledge_base.messages.invalid_summary_model'),
            },
        ]);
    }

    /**
     * 取得可用于知识库的启用模型选项。
     *
     * @return AiModelOptionData[]
     */
    public function getKnowledgeBaseModelOptions(AiModelType $type): array
    {
        return $this->activeSystemModelsQuery($type)
            ->get()
            ->map(fn (AiModel $model) => AiModelOptionData::fromModel($model))
            ->all();
    }

    /**
     * 启用模型的统一查询（供应商存在即可用），按供应商和模型自身的 sort_order 排序。
     */
    private function activeSystemModelsQuery(AiModelType $type): Builder
    {
        return AiModel::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->whereHas('provider')
            ->with('provider')
            ->orderBy(
                AiProvider::query()
                    ->select('sort_order')
                    ->whereColumn('ai_providers.id', 'ai_models.ai_provider_id')
                    ->limit(1),
            )
            ->orderBy('sort_order');
    }
}
