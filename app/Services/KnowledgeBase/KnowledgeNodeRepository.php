<?php

namespace App\Services\KnowledgeBase;

use App\Enums\KnowledgeIndexingStrategy;
use App\Enums\KnowledgeNodeKind;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeQaEntry;
use App\Settings\KnowledgeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 知识库 RAG 节点写入与清理仓库，封装 knowledge_nodes 与 vec0 向量表之间的事务边界。
 *
 * 职责分工：
 *  - WriteCanonicalChunksAction 通过 `writeCanonicalSegments()` / `writeQaCanonicalNodes()` 写入
 *    `strategy=text` 的 canonical 节点，作为全文检索 / 向量检索 / grep 的共同载体；
 *  - IndexKnowledgeDocumentVectorAction 通过 `attachVectors()` 给同一批 canonical 节点追加 vec0 行；
 *  - IndexKnowledgeDocumentRaptorAction 通过 `writeSummaryNode()` / `setParentForNodes()` 在 canonical 节点之上
 *    叠加 `strategy=raptor, kind=summary` 的内部树节点（仅在摘要节点间用 parent_id 串成树）。
 */
class KnowledgeNodeRepository
{
    public function __construct(
        private readonly KnowledgeVectorTableManager $vectorTables,
    ) {}

    /**
     * 清空指定文档下某条策略的全部节点（含向量），用于重建索引。
     */
    public function purgeStrategyForDocument(KnowledgeDocument $document, KnowledgeIndexingStrategy $strategy): void
    {
        $this->purgeNodesWhere(['document_id' => (string) $document->id], $strategy);
    }

    /**
     * 清空指定问答条目下某条策略的全部节点（含向量），用于重建问答索引。
     */
    public function purgeStrategyForQaEntry(KnowledgeQaEntry $entry, KnowledgeIndexingStrategy $strategy): void
    {
        $this->purgeNodesWhere(['qa_entry_id' => (string) $entry->id], $strategy);
    }

    /**
     * 删除文档时一次性清掉该文档全部策略的节点与向量。
     */
    public function purgeAllForDocument(KnowledgeDocument $document): void
    {
        $this->purgeNodesWhere(['document_id' => (string) $document->id], strategy: null);
    }

    /**
     * 删除问答时一次性清掉该问答全部策略的节点与向量。
     */
    public function purgeAllForQaEntry(KnowledgeQaEntry $entry): void
    {
        $this->purgeNodesWhere(['qa_entry_id' => (string) $entry->id], strategy: null);
    }

    /**
     * 删除知识库时按 knowledge_base_id 一次性清掉所有节点与向量。
     */
    public function purgeAllForKnowledgeBase(KnowledgeBase $knowledgeBase): void
    {
        $this->purgeNodesWhere(['knowledge_base_id' => (string) $knowledgeBase->id], strategy: null);
    }

    /**
     * 按筛选条件清空一组节点及其对应向量。
     *
     * @param  array<string, string>  $where  必须能唯一定位一组节点的筛选字段（如 document_id / qa_entry_id / knowledge_base_id）
     * @param  KnowledgeIndexingStrategy|null  $strategy  限定到特定策略；null 表示该 scope 下所有策略
     */
    private function purgeNodesWhere(array $where, ?KnowledgeIndexingStrategy $strategy): void
    {
        $query = KnowledgeNode::query()->where($where);
        if ($strategy !== null) {
            $query->where('strategy', $strategy);
        }

        $nodes = $query->get(['id', 'embedding_dim']);
        if ($nodes->isEmpty()) {
            return;
        }

        $byDimension = [];
        foreach ($nodes as $node) {
            $dim = (int) $node->embedding_dim;
            $byDimension[$dim] ??= [];
            $byDimension[$dim][] = (string) $node->id;
        }

        DB::connection('sqlite_rag')->transaction(function () use ($where, $strategy, $byDimension): void {
            foreach ($byDimension as $dim => $ids) {
                if ($dim > 0) {
                    $this->vectorTables->deleteVectors($dim, $ids);
                }
            }

            $deleteQuery = KnowledgeNode::query()->where($where);
            if ($strategy !== null) {
                $deleteQuery->where('strategy', $strategy);
            }
            $deleteQuery->delete();
        });
    }

    /**
     * 批量写入 canonical 文本分段节点（strategy=text, kind=segment, level=0）。
     *
     * 写入时不附带向量；后续 IndexKnowledgeDocumentVectorAction 通过 attachVectors() 把 embedding 挂回这些节点。
     *
     * @param  list<array{
     *     content: string,
     *     content_format?: string,
     *     heading_path?: string|null,
     *     byte_start?: int|null,
     *     byte_end?: int|null,
     *     token_count?: int|null,
     *     metadata?: array<string, mixed>|null,
     * }>  $segments
     * @return list<string> 写入的节点 ID 列表
     */
    public function writeCanonicalSegments(
        KnowledgeBase $knowledgeBase,
        KnowledgeDocument $document,
        array $segments,
    ): array {
        if ($segments === []) {
            return [];
        }

        $now = now();
        $rows = [];
        $ids = [];

        foreach ($segments as $segment) {
            $id = (string) Str::ulid();
            $ids[] = $id;
            $rows[] = [
                'id' => $id,
                'knowledge_base_id' => (string) $knowledgeBase->id,
                'document_id' => (string) $document->id,
                'qa_entry_id' => null,
                'qa_question_id' => null,
                'parent_id' => null,
                'strategy' => KnowledgeIndexingStrategy::Text,
                'level' => 0,
                'kind' => KnowledgeNodeKind::Segment,
                'content' => (string) $segment['content'],
                'content_format' => (string) ($segment['content_format'] ?? 'markdown'),
                'heading_path' => $segment['heading_path'] ?? null,
                'byte_start' => $segment['byte_start'] ?? null,
                'byte_end' => $segment['byte_end'] ?? null,
                'token_count' => $segment['token_count'] ?? null,
                'embedding_model_id' => null,
                'embedding_dim' => 0,
                'metadata' => $this->encodeMetadata($segment['metadata'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        KnowledgeNode::query()->insert($rows);

        return $ids;
    }

    /**
     * 批量写入问答 canonical 节点（主问题 / 相似问法 / 答案），strategy=text。
     *
     * @param  list<array{
     *     content: string,
     *     content_format?: string,
     *     qa_question_id?: string|null,
     *     metadata?: array<string, mixed>|null,
     * }>  $items
     * @return list<string>
     */
    public function writeQaCanonicalNodes(
        KnowledgeBase $knowledgeBase,
        KnowledgeQaEntry $entry,
        array $items,
    ): array {
        if ($items === []) {
            return [];
        }

        $now = now();
        $rows = [];
        $ids = [];

        foreach ($items as $item) {
            $id = (string) Str::ulid();
            $ids[] = $id;
            $rows[] = [
                'id' => $id,
                'knowledge_base_id' => (string) $knowledgeBase->id,
                'document_id' => null,
                'qa_entry_id' => (string) $entry->id,
                'qa_question_id' => isset($item['qa_question_id']) && filled($item['qa_question_id'])
                    ? (string) $item['qa_question_id']
                    : null,
                'parent_id' => null,
                'strategy' => KnowledgeIndexingStrategy::Text,
                'level' => 0,
                'kind' => KnowledgeNodeKind::Segment,
                'content' => (string) $item['content'],
                'content_format' => (string) ($item['content_format'] ?? 'text'),
                'heading_path' => null,
                'byte_start' => null,
                'byte_end' => null,
                'token_count' => null,
                'embedding_model_id' => null,
                'embedding_dim' => 0,
                'metadata' => $this->encodeMetadata($item['metadata'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        KnowledgeNode::query()->insert($rows);

        return $ids;
    }

    /**
     * 给一组已写入的 canonical 节点附加 vec0 向量行，并把 embedding_dim / embedding_model_id 落到节点行上。
     *
     * @param  array<string, list<float>>  $embeddings  node_id => embedding 向量
     */
    public function attachVectors(
        KnowledgeBase $knowledgeBase,
        int $embeddingDimension,
        array $embeddings,
    ): void {
        if ($embeddings === [] || $embeddingDimension <= 0) {
            return;
        }

        $nodeIds = array_keys($embeddings);
        $modelId = $this->embeddingModelId();

        DB::connection('sqlite_rag')->transaction(function () use ($embeddingDimension, $embeddings, $nodeIds, $modelId): void {
            foreach ($embeddings as $nodeId => $embedding) {
                $this->vectorTables->upsertVector(
                    $embeddingDimension,
                    (string) $nodeId,
                    array_map(static fn ($v) => (float) $v, $embedding),
                );
            }

            KnowledgeNode::query()
                ->whereIn('id', $nodeIds)
                ->update([
                    'embedding_dim' => $embeddingDimension,
                    'embedding_model_id' => $modelId,
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * 单独写入一个 RAPTOR 摘要节点，返回 node id。
     *
     * 摘要节点位于 strategy=raptor / kind=summary / level>=1，其 children_ids 通过 metadata 透传，
     * 同时 setParentForNodes() 会把上一层摘要节点的 parent_id 指向本节点，串成上行树。
     * canonical text 叶子不会被改 parent_id，避免污染全文 / 向量检索。
     *
     * @param  list<string>  $childrenIds
     * @param  list<float>|null  $embedding
     * @param  array<string, mixed>|null  $metadata
     */
    public function writeSummaryNode(
        KnowledgeBase $knowledgeBase,
        KnowledgeDocument $document,
        int $level,
        ?string $parentId,
        string $content,
        int $embeddingDimension,
        ?array $embedding,
        array $childrenIds,
        ?array $metadata = null,
    ): string {
        $id = (string) Str::ulid();
        $now = now();

        $metadataPayload = ['children_ids' => $childrenIds];
        if (is_array($metadata)) {
            $metadataPayload = array_merge($metadataPayload, $metadata);
        }

        DB::connection('sqlite_rag')->transaction(function () use (
            $id,
            $knowledgeBase,
            $document,
            $level,
            $parentId,
            $content,
            $embeddingDimension,
            $embedding,
            $metadataPayload,
            $now,
        ): void {
            KnowledgeNode::query()->insert([
                'id' => $id,
                'knowledge_base_id' => (string) $knowledgeBase->id,
                'document_id' => (string) $document->id,
                'qa_entry_id' => null,
                'qa_question_id' => null,
                'parent_id' => $parentId,
                'strategy' => KnowledgeIndexingStrategy::Raptor,
                'level' => $level,
                'kind' => KnowledgeNodeKind::Summary,
                'content' => $content,
                'content_format' => 'markdown',
                'heading_path' => null,
                'byte_start' => null,
                'byte_end' => null,
                'token_count' => null,
                'embedding_model_id' => $embeddingDimension > 0 ? $this->embeddingModelId() : null,
                'embedding_dim' => $embeddingDimension,
                'metadata' => json_encode($metadataPayload, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (is_array($embedding) && $embedding !== []) {
                $this->vectorTables->upsertVector(
                    $embeddingDimension,
                    $id,
                    array_map(static fn ($v) => (float) $v, $embedding),
                );
            }
        });

        return $id;
    }

    /**
     * 把指定子节点的 parent_id 一次性更新到新父节点上，用于 RAPTOR 写完每层摘要后串起树形结构。
     *
     * 仅应作用于 strategy=raptor 节点。canonical text 叶子不要参与 parent 串接，
     * 否则会污染全文 / 向量检索的语义。
     *
     * @param  list<string>  $childrenIds
     */
    public function setParentForNodes(array $childrenIds, string $parentId): void
    {
        if ($childrenIds === []) {
            return;
        }

        KnowledgeNode::query()
            ->whereIn('id', $childrenIds)
            ->where('strategy', KnowledgeIndexingStrategy::Raptor)
            ->update(['parent_id' => $parentId, 'updated_at' => now()]);
    }

    /**
     * 返回当前知识库配置的嵌入模型 ID；未配置时返回 null。
     */
    private function embeddingModelId(): ?string
    {
        /** @var KnowledgeSettings $settings */
        $settings = app(KnowledgeSettings::class);
        $settings->refresh();

        return filled($settings->embedding_model_id)
            ? (string) $settings->embedding_model_id
            : null;
    }

    /**
     * 把外部 metadata 转 JSON；空值统一返回 null，避免 SQLite 把空数组当成 "[]" 占位。
     */
    private function encodeMetadata(?array $metadata): ?string
    {
        if (! is_array($metadata) || $metadata === []) {
            return null;
        }

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }
}
