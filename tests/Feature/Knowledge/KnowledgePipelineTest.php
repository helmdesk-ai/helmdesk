<?php

use App\Actions\KnowledgeBase\Indexing\DispatchKnowledgeDocumentPipelineAction;
use App\Actions\KnowledgeBase\Indexing\IndexKnowledgeDocumentRaptorAction;
use App\Actions\KnowledgeBase\Indexing\IndexKnowledgeDocumentVectorAction;
use App\Actions\KnowledgeBase\Indexing\ParseKnowledgeDocumentAction;
use App\Actions\KnowledgeBase\Indexing\WriteCanonicalChunksAction;
use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeIndexingStrategy;
use App\Enums\KnowledgeNodeKind;
use App\Jobs\KnowledgeDocument\IndexRaptorKnowledgeDocumentJob;
use App\Jobs\KnowledgeDocument\IndexVectorKnowledgeDocumentJob;
use App\Jobs\KnowledgeDocument\ParseKnowledgeDocumentJob;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeNode;
use App\Services\KnowledgeBase\GoKnowledgeBridge;
use App\Services\KnowledgeBase\Parsing\DocumentParserManager;
use App\Services\KnowledgeBase\Parsing\ParsedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\WithWorkspace;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace();
    $provider = AiProvider::query()->create([
        'workspace_id' => $this->workspace->id,
        'brand' => 'custom-openai',
        'slug' => 'kb-pipeline-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Pipeline Provider',
        'protocol' => 'openai',
        'credential_fields' => [],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->embeddingModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'kb-pipeline-embedding-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Pipeline Embedding Model',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->summaryModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'kb-pipeline-summary-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Pipeline Summary Model',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 1,
    ]);
    $this->workspace->update([
        'knowledge_embedding_model_id' => $this->embeddingModel->id,
        'knowledge_summary_model_id' => $this->summaryModel->id,
        'knowledge_vector_index_enabled' => true,
        'knowledge_raptor_index_enabled' => false,
        'knowledge_chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
        'knowledge_chunk_max_tokens' => 256,
        'knowledge_chunk_overlap_tokens' => 32,
    ]);
    $this->kb = KnowledgeBase::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
});

test('编排器为启用的索引策略写入 Pending，并按策略派发 Job', function () {
    Bus::fake();

    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $this->kb->id,
        'parse_status' => KnowledgeDocumentParseStatus::Pending,
    ]);

    app(DispatchKnowledgeDocumentPipelineAction::class)->handle($document);

    $document->refresh();
    expect($document->parse_status)->toBe(KnowledgeDocumentParseStatus::Pending)
        ->and($document->vector_status)->toBe(KnowledgeDocumentIndexingStatus::Pending)
        ->and($document->raptor_status)->toBe(KnowledgeDocumentIndexingStatus::Idle);

    Bus::assertDispatched(ParseKnowledgeDocumentJob::class);
    Bus::assertNotDispatched(IndexVectorKnowledgeDocumentJob::class);
    Bus::assertNotDispatched(IndexRaptorKnowledgeDocumentJob::class);
});

test('解析成功后会派发已启用策略对应的索引 Job', function () {
    Bus::fake();

    $this->workspace->update([
        'knowledge_vector_index_enabled' => true,
        'knowledge_raptor_index_enabled' => true,
    ]);

    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $this->kb->id,
        'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
        'parsed_content' => "# 标题\n\n正文段落",
        'parsed_content_format' => 'markdown',
    ]);

    app(DispatchKnowledgeDocumentPipelineAction::class)
        ->dispatchIndexingForParsedDocument($document);

    Bus::assertDispatched(IndexVectorKnowledgeDocumentJob::class);
    Bus::assertDispatched(IndexRaptorKnowledgeDocumentJob::class);
});

test('ParseAction 调用 DocumentParserManager 成功后写入 parsed_content 并把策略转 Pending', function () {
    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $this->kb->id,
        'parse_status' => KnowledgeDocumentParseStatus::Pending,
        'content' => "# 测试\n\n这是手动写的文档内容，长度足够。",
    ]);

    mock(DocumentParserManager::class, function ($mock): void {
        $mock->shouldReceive('parse')->once()->andReturn(new ParsedDocument(
            markdown: "# 测试\n\n这是手动写的文档内容，长度足够。",
            contentFormat: 'markdown',
            metadata: ['parser' => 'text', 'source' => 'unit-test'],
        ));
    });

    $changed = app(ParseKnowledgeDocumentAction::class)->handle($document);

    expect($changed)->toBeTrue();
    $document->refresh();
    expect($document->parse_status)->toBe(KnowledgeDocumentParseStatus::Succeeded)
        ->and($document->parsed_content_format)->toBe('markdown')
        ->and($document->parsed_content)->toContain('测试')
        ->and($document->parse_metadata['outline'][0]['heading'] ?? null)->toBe('测试')
        ->and($document->vector_status)->toBe(KnowledgeDocumentIndexingStatus::Pending)
        ->and($document->raptor_status)->toBe(KnowledgeDocumentIndexingStatus::Idle)
        ->and($document->status)->toBe(KnowledgeDocumentStatus::Indexing);
});

test('ParseAction 失败时把 parse_status 置 Failed 并向上抛出', function () {
    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $this->kb->id,
        'parse_status' => KnowledgeDocumentParseStatus::Pending,
        'content' => '一些手动内容',
    ]);

    mock(DocumentParserManager::class, function ($mock): void {
        $mock->shouldReceive('parse')->once()->andThrow(new RuntimeException('parser exploded'));
    });

    expect(fn () => app(ParseKnowledgeDocumentAction::class)->handle($document))
        ->toThrow(RuntimeException::class, 'parser exploded');

    $document->refresh();
    expect($document->parse_status)->toBe(KnowledgeDocumentParseStatus::Failed)
        ->and($document->parse_error)->toContain('parser exploded')
        ->and($document->status)->toBe(KnowledgeDocumentStatus::Failed);
});

test('WriteCanonicalChunksAction 同时写 canonical 节点、knowledge_fts 与 knowledge_outlines', function () {
    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $this->kb->id,
        'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
        'parsed_content' => "# 标题\n\n正文段落 1\n\n正文段落 2",
        'parsed_content_format' => 'markdown',
        'parse_metadata' => ['outline' => [['heading' => '标题', 'level' => 1]]],
    ]);

    $nodes = app(WriteCanonicalChunksAction::class)->forDocument($document);

    $ftsRows = DB::connection('sqlite_rag')
        ->table('knowledge_fts')
        ->where('document_id', (string) $document->id)
        ->count();
    $outlineRow = DB::connection('sqlite_rag')
        ->table('knowledge_outlines')
        ->where('document_id', (string) $document->id)
        ->first();
    $canonical = KnowledgeNode::query()
        ->where('document_id', (string) $document->id)
        ->where('strategy', KnowledgeIndexingStrategy::Text->value)
        ->where('kind', KnowledgeNodeKind::Segment->value)
        ->get();

    expect($nodes->count())->toBeGreaterThanOrEqual(1)
        ->and($canonical->count())->toBe($nodes->count())
        ->and($ftsRows)->toBe($nodes->count())
        ->and($outlineRow)->not->toBeNull();
});

test('VectorAction 把 canonical 节点附加向量并把 vector_status 置 Succeeded', function () {
    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $this->kb->id,
        'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
        'parsed_content' => "# 标题\n\n第一段\n\n第二段",
        'parsed_content_format' => 'markdown',
        'vector_status' => KnowledgeDocumentIndexingStatus::Pending,
    ]);

    mock(GoKnowledgeBridge::class, function ($mock): void {
        $mock->shouldReceive('embedTexts')->andReturnUsing(static function ($_provider, $_model, $_credentials, array $contents): array {
            return [
                'dimension' => 3,
                'embeddings' => array_map(static fn () => [0.1, 0.2, 0.3], $contents),
            ];
        });
    });

    app(WriteCanonicalChunksAction::class)->forDocument($document);
    app(IndexKnowledgeDocumentVectorAction::class)->handle($document);

    $document->refresh();
    expect($document->vector_status)->toBe(KnowledgeDocumentIndexingStatus::Succeeded);

    $nodes = KnowledgeNode::query()
        ->where('document_id', (string) $document->id)
        ->where('strategy', KnowledgeIndexingStrategy::Text->value)
        ->where('kind', KnowledgeNodeKind::Segment->value)
        ->get();

    expect($nodes->count())->toBeGreaterThanOrEqual(1)
        ->and($nodes->every(fn ($node) => (int) $node->embedding_dim === 3))->toBeTrue();
});

test('VectorAction 使用句子 embedding 聚合语义分段', function () {
    $this->workspace->update([
        'knowledge_chunking_strategy' => KnowledgeChunkingStrategy::Semantic->value,
        'knowledge_chunk_max_tokens' => 256,
        'knowledge_chunk_overlap_tokens' => 0,
    ]);

    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $this->kb->id,
        'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
        'parsed_content' => "# 标题\n\n猫喜欢鱼。猫会抓老鼠。数据库支持事务。索引提升查询速度。",
        'parsed_content_format' => 'markdown',
        'vector_status' => KnowledgeDocumentIndexingStatus::Pending,
    ]);

    mock(GoKnowledgeBridge::class, function ($mock): void {
        $mock->shouldReceive('embedTexts')->andReturnUsing(static function ($_provider, $_model, $_credentials, array $contents): array {
            $embeddings = count($contents) === 4
                ? [[1.0, 0.0], [0.98, 0.02], [0.0, 1.0], [0.02, 0.98]]
                : array_map(static fn () => [0.5, 0.5], $contents);

            return [
                'dimension' => 2,
                'embeddings' => $embeddings,
            ];
        });
    });

    app(WriteCanonicalChunksAction::class)->forDocument($document);
    app(IndexKnowledgeDocumentVectorAction::class)->handle($document);

    $contents = KnowledgeNode::query()
        ->where('document_id', (string) $document->id)
        ->where('strategy', KnowledgeIndexingStrategy::Text->value)
        ->where('kind', KnowledgeNodeKind::Segment->value)
        ->orderByRaw('COALESCE(byte_start, 0) ASC')
        ->orderBy('id')
        ->pluck('content')
        ->all();

    expect($contents)->toHaveCount(2)
        ->and($contents[0])->toContain('猫喜欢鱼')
        ->and($contents[0])->toContain('猫会抓老鼠')
        ->and($contents[1])->toContain('数据库支持事务')
        ->and($contents[1])->toContain('索引提升查询速度');
});

test('RaptorAction 使用摘要模型生成摘要树并把 raptor_status 置 Succeeded', function () {
    $provider = AiProvider::query()->create([
        'workspace_id' => $this->workspace->id,
        'brand' => 'custom-openai',
        'slug' => 'kb-raptor-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Raptor Provider',
        'protocol' => 'openai',
        'credential_fields' => [],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $summaryModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'kb-raptor-summary-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Raptor Summary Model',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->workspace->update([
        'knowledge_summary_model_id' => $summaryModel->id,
        'knowledge_vector_index_enabled' => false,
        'knowledge_raptor_index_enabled' => true,
        // 用 Fixed 策略 + 较小的 chunk 上限，保证四段内容各自落到独立的叶子段，
        // 这样后面才能验证摘要节点把它们聚到自己 parent_id 下。
        'knowledge_chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
        'knowledge_chunk_max_tokens' => 16,
        'knowledge_chunk_overlap_tokens' => 0,
    ]);
    $knowledgeBase = KnowledgeBase::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $knowledgeBase->id,
        'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
        'parsed_content' => "# 标题\n\n第一段内容比较长一些方便切段\n\n第二段内容比较长一些方便切段\n\n第三段内容比较长一些方便切段\n\n第四段内容比较长一些方便切段",
        'parsed_content_format' => 'markdown',
        'raptor_status' => KnowledgeDocumentIndexingStatus::Pending,
    ]);

    mock(GoKnowledgeBridge::class, function ($mock): void {
        $mock->shouldReceive('embedTexts')->andReturnUsing(static function ($_provider, $_model, $_credentials, array $contents): array {
            return [
                'dimension' => 3,
                'embeddings' => array_map(static fn () => [0.1, 0.2, 0.3], $contents),
            ];
        });
        $mock->shouldReceive('summarizeBatches')->andReturnUsing(static function ($_provider, $_model, $_credentials, array $batches): array {
            return [
                'summaries' => array_map(
                    static fn (array $contents, int $index): string => '摘要 '.($index + 1).': '.implode(' ', $contents),
                    $batches,
                    array_keys($batches),
                ),
            ];
        });
    });

    app(WriteCanonicalChunksAction::class)->forDocument($document);
    app(IndexKnowledgeDocumentRaptorAction::class)->handle($document);

    $document->refresh();
    // 新模型下，RAPTOR 叶子直接复用 canonical text 节点；摘要节点是 strategy=raptor / kind=summary。
    $leafNodes = KnowledgeNode::query()
        ->where('document_id', (string) $document->id)
        ->where('strategy', KnowledgeIndexingStrategy::Text->value)
        ->where('kind', KnowledgeNodeKind::Segment->value)
        ->get();
    $summaryNodes = KnowledgeNode::query()
        ->where('document_id', (string) $document->id)
        ->where('strategy', KnowledgeIndexingStrategy::Raptor->value)
        ->where('kind', KnowledgeNodeKind::Summary->value)
        ->get();

    $leafIds = $leafNodes->pluck('id')->map(static fn ($id) => (string) $id)->all();
    $coveredLeafIds = collect();
    foreach ($summaryNodes as $summary) {
        $children = $summary->metadata['children_ids'] ?? [];
        if (is_array($children)) {
            $coveredLeafIds = $coveredLeafIds->merge($children);
        }
    }

    expect($document->raptor_status)->toBe(KnowledgeDocumentIndexingStatus::Succeeded)
        ->and($leafNodes->count())->toBeGreaterThanOrEqual(1)
        ->and($summaryNodes->count())->toBeGreaterThanOrEqual(1)
        // 工作区关闭了 Vector 索引，canonical 叶子不应被偷偷打上向量维度。
        ->and($leafNodes->every(fn ($node) => (int) $node->embedding_dim === 0))->toBeTrue()
        // 摘要节点自带嵌入维度，作为 RAPTOR 召回的主要载体。
        ->and($summaryNodes->every(fn ($node) => (int) $node->embedding_dim === 3))->toBeTrue()
        // canonical 叶子的 parent_id 始终保持 null，避免污染全文 / 向量检索。
        ->and($leafNodes->every(fn ($node) => $node->parent_id === null))->toBeTrue()
        // 每个 canonical 叶子都被某个一层摘要节点的 children_ids 覆盖到。
        ->and(
            collect($leafIds)
                ->every(fn (string $id) => $coveredLeafIds->contains($id))
        )->toBeTrue();
});
