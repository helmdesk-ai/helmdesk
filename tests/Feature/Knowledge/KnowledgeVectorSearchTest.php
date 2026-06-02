<?php

use App\Enums\KnowledgeIndexingStrategy;
use App\Enums\KnowledgeNodeKind;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeNode;
use App\Models\SystemContext;
use App\Services\KnowledgeBase\KnowledgeVectorTableManager;
use App\Services\KnowledgeBase\Search\VectorRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

/*
 * VectorRetriever 的边界 / 隔离行为：
 *  - 跨 systemContext 灌大量向量时，目标 systemContext 仍能从其 scope 内召回到节点；
 *  - 切换 embedding model 后既有向量按 embedding_model_id 隔离，不进入距离比较；
 *  - 允许集合为空时返回空数组。
 *
 * 用例通过 KnowledgeVectorTableManager 直接写入假向量，绕开 Go 桥的 embedder，保持离线。
 */

beforeEach(function (): void {
    $this->user = $this->createUserWithSystem();
    $this->provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'vec-search-'.Str::lower((string) Str::ulid()),
        'name' => 'Vector Search Provider',
        'protocol' => 'openai',
        'credential_fields' => [],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->embeddingModel = AiModel::query()->create([
        'ai_provider_id' => $this->provider->id,
        'model_id' => 'vec-search-embedding-'.Str::lower((string) Str::ulid()),
        'name' => 'Vec Search Embedding',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->kb = KnowledgeBase::factory()->create([
        'name' => '向量召回测试库',
    ]);
});

/**
 * 直接写入一条带向量的 strategy=text 节点，供 VectorRetriever 命中。
 *
 * @param  list<float>  $embedding
 */
function seedVectorNode(
    SystemContext $systemContext,
    KnowledgeBase $kb,
    AiModel $embeddingModel,
    array $embedding,
    string $content = '向量节点测试内容',
): KnowledgeNode {
    $node = KnowledgeNode::query()->create([
        'id' => (string) Str::ulid(),
        'knowledge_base_id' => $kb->id,
        'document_id' => null,
        'qa_entry_id' => null,
        'qa_question_id' => null,
        'parent_id' => null,
        'strategy' => KnowledgeIndexingStrategy::Text->value,
        'level' => 0,
        'kind' => KnowledgeNodeKind::Segment->value,
        'content' => $content,
        'content_format' => 'markdown',
        'heading_path' => null,
        'byte_start' => null,
        'byte_end' => null,
        'token_count' => null,
        'embedding_model_id' => $embeddingModel->id,
        'embedding_dim' => count($embedding),
        'metadata' => null,
    ]);

    app(KnowledgeVectorTableManager::class)->upsertVector(
        count($embedding),
        $node->id,
        $embedding,
    );

    return $node;
}

test('VectorRetriever 跨 systemContext 时仍能从小集合的目标 systemContext 召回到节点', function (): void {
    $dim = 4;
    $queryEmbedding = [1.0, 0.0, 0.0, 0.0];

    // 目标 systemContext 只有 1 个与 query 完全一致的节点；其它 systemContext 灌 200 条略远的节点。
    // VectorRetriever 应当先按 scope 取允许集合，再让 KNN 在该集合内排序，目标节点保持命中。
    $targetNode = seedVectorNode($this->systemContext, $this->kb, $this->embeddingModel, [1.0, 0.0, 0.0, 0.0], '目标节点');

    $otherSystem = SystemContext::factory()->create();
    $otherProvider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'other-'.Str::lower((string) Str::ulid()),
        'name' => 'Other Provider',
        'protocol' => 'openai',
        'credential_fields' => [],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $otherModel = AiModel::query()->create([
        'ai_provider_id' => $otherProvider->id,
        'model_id' => 'other-embedding-'.Str::lower((string) Str::ulid()),
        'name' => 'Other Embedding',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $otherKb = KnowledgeBase::factory()->create([]);
    for ($i = 0; $i < 200; $i++) {
        seedVectorNode($otherSystem, $otherKb, $otherModel, [0.99, 0.01 * $i, 0.0, 0.0]);
    }

    $hits = app(VectorRetriever::class)->retrieve(
        knowledgeBaseIds: [$this->kb->id],
        dimension: $dim,
        queryEmbeddings: [$queryEmbedding],
        strategies: [KnowledgeIndexingStrategy::Text],
        topK: 5,
        embeddingModelId: $this->embeddingModel->id,
    );

    expect($hits)->not->toBeEmpty();
    expect($hits[0]->knowledgeNodeId)->toBe($targetNode->id);
});

test('VectorRetriever 按 embedding_model_id 隔离：切换模型后不同模型向量不会参与召回', function (): void {
    $dim = 4;
    seedVectorNode($this->systemContext, $this->kb, $this->embeddingModel, [1.0, 0.0, 0.0, 0.0]);

    // systemContext 切到新的 embedding 模型，既有节点 embedding_model_id 仍是原模型。
    $newModel = AiModel::query()->create([
        'ai_provider_id' => $this->provider->id,
        'model_id' => 'vec-new-'.Str::lower((string) Str::ulid()),
        'name' => 'New Embedding',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $hits = app(VectorRetriever::class)->retrieve(
        knowledgeBaseIds: [$this->kb->id],
        dimension: $dim,
        queryEmbeddings: [[1.0, 0.0, 0.0, 0.0]],
        strategies: [KnowledgeIndexingStrategy::Text],
        topK: 5,
        embeddingModelId: $newModel->id,
    );

    expect($hits)->toBe([]);
});

test('VectorRetriever 允许集合为空时直接返回空数组', function (): void {
    $hits = app(VectorRetriever::class)->retrieve(
        knowledgeBaseIds: [$this->kb->id],
        dimension: 4,
        queryEmbeddings: [[1.0, 0.0, 0.0, 0.0]],
        strategies: [KnowledgeIndexingStrategy::Text],
        topK: 5,
        embeddingModelId: $this->embeddingModel->id,
    );

    expect($hits)->toBe([]);
});
