<?php

namespace App\Actions\KnowledgeBase;

use App\Data\KnowledgeBase\FormUpdateWorkspaceKnowledgeSettingsData;
use App\Data\WorkspaceUserContextData;
use App\Enums\AiModelType;
use App\Enums\KnowledgeIndexingStrategy;
use App\Jobs\KnowledgeDocument\RebuildWorkspaceKnowledgeIndexJob;
use App\Models\AiModel;
use App\Models\Workspace;
use App\Services\AiRuntime\AiModelResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新工作区知识库统一检索配置，并按需派发索引重建任务。
 *
 * - 保存嵌入模型、向量维度、重排序模型、摘要模型、分段策略等检索参数；
 * - 维度由用户在表单里手填（不少模型支持可变维度，统一不做后端探测），与嵌入模型一起作为
 *   "查询侧 / 索引侧必须对齐"的可信源；
 * - 当配置变更（含维度变化）会让索引失效时，把"清旧索引 + 派发逐条 Job"的耗时工作交给
 *   RebuildWorkspaceKnowledgeIndexJob 异步处理，HTTP 请求只负责入库与派发。
 */
class UpdateWorkspaceKnowledgeSettingsAction
{
    use AsAction;

    public function __construct(
        private readonly AiModelResolver $resolver,
    ) {}

    /**
     * 保存检索配置并视需要派发索引重建任务。
     */
    public function handle(Workspace $workspace, FormUpdateWorkspaceKnowledgeSettingsData $data): void
    {
        $this->ensureRequiredFieldsArePresent($data);

        // 启用 vector 或 raptor 中任意一个都需要嵌入模型——RAPTOR 现在也会给摘要节点
        // 走嵌入用于向量召回，所以同样依赖嵌入模型。
        $embeddingRequired = $data->vector_index_enabled || $data->raptor_index_enabled;
        $embeddingModel = $this->resolveActiveModel($workspace, $data->embedding_model_id, AiModelType::Embedding, 'embedding_model_id', $embeddingRequired);
        $rerankModel = $this->resolveActiveModel($workspace, $data->rerank_model_id, AiModelType::Rerank, 'rerank_model_id');
        $summaryModel = $this->resolveActiveModel($workspace, $data->summary_model_id, AiModelType::Llm, 'summary_model_id', $data->raptor_index_enabled);

        $previousDimension = $workspace->knowledge_embedding_dimension !== null
            ? (int) $workspace->knowledge_embedding_dimension
            : null;
        $newDimension = $embeddingRequired && $embeddingModel !== null
            ? (int) $data->embedding_dimension
            : null;
        $dimensionChanged = $newDimension !== $previousDimension;

        $rebuildVectorIndex = $this->shouldRebuildVectorIndex($workspace, $data, $embeddingModel, $dimensionChanged);
        $rebuildQaVectorIndex = $this->shouldRebuildQaVectorIndex($workspace, $data, $embeddingModel, $dimensionChanged);
        $rebuildRaptorIndex = $this->shouldRebuildRaptorIndex($workspace, $data, $embeddingModel, $summaryModel, $dimensionChanged);

        $workspace->update([
            'knowledge_embedding_model_id' => $embeddingModel?->id,
            'knowledge_rerank_model_id' => $rerankModel?->id,
            'knowledge_summary_model_id' => $summaryModel?->id,
            'knowledge_embedding_dimension' => $newDimension,
            'knowledge_vector_index_enabled' => $data->vector_index_enabled,
            'knowledge_raptor_index_enabled' => $data->raptor_index_enabled,
            'knowledge_chunking_strategy' => $data->chunking_strategy,
            'knowledge_chunk_max_tokens' => $data->chunk_max_tokens,
            'knowledge_chunk_overlap_tokens' => $data->chunk_overlap_tokens,
        ]);

        $strategies = array_values(array_filter([
            $rebuildVectorIndex ? KnowledgeIndexingStrategy::Vector->value : null,
            $rebuildRaptorIndex ? KnowledgeIndexingStrategy::Raptor->value : null,
        ]));

        if ($strategies !== [] || $rebuildQaVectorIndex) {
            RebuildWorkspaceKnowledgeIndexJob::dispatch(
                workspaceId: (string) $workspace->id,
                documentStrategyValues: $strategies,
                rebuildQaVectorIndex: $rebuildQaVectorIndex,
                resetVectorTables: $dimensionChanged,
            );
        }
    }

    /**
     * 接收检索配置表单提交并回到当前页面。
     */
    public function asController(Request $request): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $this->handle($workspace, FormUpdateWorkspaceKnowledgeSettingsData::from($request));

        return back();
    }

    /**
     * 当启用条件满足且有 ID 时，解析为当前工作区可用的模型；否则返回 null。
     * resolver 内部对不存在 / 已停用的模型会抛字段级 ValidationException。
     */
    private function resolveActiveModel(Workspace $workspace, ?string $modelId, AiModelType $type, string $field, bool $enabled = true): ?AiModel
    {
        if (! $enabled || ! filled($modelId)) {
            return null;
        }

        return $this->resolver->resolveActiveKnowledgeBaseModel($workspace, (string) $modelId, $type, $field);
    }

    /**
     * 判断文档向量索引配置是否发生了需要重建的变更。
     */
    private function shouldRebuildVectorIndex(Workspace $workspace, FormUpdateWorkspaceKnowledgeSettingsData $data, ?AiModel $embeddingModel, bool $dimensionChanged): bool
    {
        return $this->shouldRebuildQaVectorIndex($workspace, $data, $embeddingModel, $dimensionChanged)
            || $workspace->knowledge_chunking_strategy !== $data->chunking_strategy
            || (int) $workspace->knowledge_chunk_max_tokens !== $data->chunk_max_tokens
            || (int) $workspace->knowledge_chunk_overlap_tokens !== $data->chunk_overlap_tokens;
    }

    /**
     * 判断问答条目向量索引配置是否发生了需要重建的变更。
     * 维度变化（即便嵌入模型 id 未变）也会让旧向量与新查询不可比，必须重建。
     */
    private function shouldRebuildQaVectorIndex(Workspace $workspace, FormUpdateWorkspaceKnowledgeSettingsData $data, ?AiModel $embeddingModel, bool $dimensionChanged): bool
    {
        return (bool) $workspace->knowledge_vector_index_enabled !== $data->vector_index_enabled
            || $this->modelIdChanged($workspace->knowledge_embedding_model_id, $embeddingModel)
            || $dimensionChanged;
    }

    /**
     * 判断 RAPTOR 索引配置是否发生了需要重建的变更。
     * RAPTOR 现在依赖嵌入模型给摘要节点生成向量，所以嵌入模型或维度变化也会触发 raptor 重建。
     */
    private function shouldRebuildRaptorIndex(Workspace $workspace, FormUpdateWorkspaceKnowledgeSettingsData $data, ?AiModel $embeddingModel, ?AiModel $summaryModel, bool $dimensionChanged): bool
    {
        return (bool) $workspace->knowledge_raptor_index_enabled !== $data->raptor_index_enabled
            || $this->modelIdChanged($workspace->knowledge_summary_model_id, $summaryModel)
            || $this->modelIdChanged($workspace->knowledge_embedding_model_id, $embeddingModel)
            || $dimensionChanged
            || $workspace->knowledge_chunking_strategy !== $data->chunking_strategy
            || (int) $workspace->knowledge_chunk_max_tokens !== $data->chunk_max_tokens
            || (int) $workspace->knowledge_chunk_overlap_tokens !== $data->chunk_overlap_tokens;
    }

    /**
     * 把保存前/后的模型 ID 都归一化成字符串后再比较，避免 null/空串误判。
     */
    private function modelIdChanged(?string $previousId, ?AiModel $newModel): bool
    {
        return (string) ($previousId ?? '') !== (string) ($newModel?->id ?? '');
    }

    /**
     * 校验启用索引策略时必须填写的字段，缺失时抛出字段级验证异常。
     * 注意：RAPTOR 现在同样需要嵌入模型给摘要节点过向量。
     */
    private function ensureRequiredFieldsArePresent(FormUpdateWorkspaceKnowledgeSettingsData $data): void
    {
        $messages = [];
        $embeddingRequired = $data->vector_index_enabled || $data->raptor_index_enabled;

        if ($embeddingRequired && ! filled($data->embedding_model_id)) {
            $messages['embedding_model_id'] = __('knowledge_base.messages.invalid_embedding_model');
        }

        if ($embeddingRequired && $data->embedding_dimension === null) {
            $messages['embedding_dimension'] = __('knowledge_base.messages.invalid_embedding_dimension');
        }

        if ($data->raptor_index_enabled && ! filled($data->summary_model_id)) {
            $messages['summary_model_id'] = __('knowledge_base.messages.invalid_summary_model');
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
