<?php

use App\Actions\KnowledgeBase\Indexing\IndexKnowledgeDocumentRaptorAction;
use App\Actions\KnowledgeBase\Indexing\IndexKnowledgeDocumentVectorAction;
use App\Actions\KnowledgeBase\Indexing\WriteCanonicalChunksAction;
use App\Enums\AiProviderProtocol;
use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeIndexingStrategy;
use App\Enums\KnowledgeNodeKind;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function (): void {
    if ((string) env('KNOWLEDGE_RUNTIME_LIVE') !== '1') {
        $this->markTestSkipped('Set KNOWLEDGE_RUNTIME_LIVE=1 to run the live knowledge runtime integration test.');
    }

    foreach (['GO_RUNTIME_BASE_URL', 'HELMDESK_INTERNAL_BRIDGE_TOKEN', 'OPENROUTER_API_KEY'] as $key) {
        if (blank(env($key))) {
            $this->markTestSkipped("Set {$key} to run the live knowledge runtime integration test.");
        }
    }

    $this->user = $this->createUserWithWorkspace();
});

test('真实运行时完成 canonical 段 + 向量索引', function (): void {
    $provider = createLiveOpenRouterProvider((string) $this->workspace->id);
    $embeddingModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => (string) env('OPENROUTER_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
        'name' => 'OpenRouter Embedding',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $this->workspace->update([
        'knowledge_embedding_model_id' => $embeddingModel->id,
        'knowledge_vector_index_enabled' => true,
        'knowledge_raptor_index_enabled' => false,
        'knowledge_chunking_strategy' => KnowledgeChunkingStrategy::Semantic->value,
        'knowledge_chunk_max_tokens' => 80,
        'knowledge_chunk_overlap_tokens' => 0,
    ]);
    $knowledgeBase = KnowledgeBase::factory()->create([
    ]);
    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $knowledgeBase->id,
        'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
        'parsed_content' => "# 产品说明\n\nHelmdesk 支持访客会话、工单流转和知识库问答。语义分段会把主题相近的句子合并。向量索引用于相似内容匹配。",
        'parsed_content_format' => 'markdown',
        'vector_status' => KnowledgeDocumentIndexingStatus::Pending,
    ]);

    app(WriteCanonicalChunksAction::class)->forDocument($document);
    app(IndexKnowledgeDocumentVectorAction::class)->handle($document);

    $document->refresh();
    $canonicalNodes = KnowledgeNode::query()
        ->where('document_id', (string) $document->id)
        ->where('strategy', KnowledgeIndexingStrategy::Text->value)
        ->where('kind', KnowledgeNodeKind::Segment->value)
        ->get();
    $vectorTable = DB::connection('sqlite_rag')
        ->table('knowledge_vector_tables')
        ->where('dimension', '>', 0)
        ->first();

    expect($document->vector_status)->toBe(KnowledgeDocumentIndexingStatus::Succeeded)
        ->and($canonicalNodes->count())->toBeGreaterThan(0)
        ->and($canonicalNodes->first()->embedding_dim)->toBeGreaterThan(0)
        ->and((int) ($vectorTable->dimension ?? 0))->toBeGreaterThan(0);
});

test('真实运行时完成 RAPTOR 摘要树', function (): void {
    $provider = createLiveOpenRouterProvider((string) $this->workspace->id);
    $summaryModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => (string) env('OPENROUTER_LLM_MODEL', 'deepseek/deepseek-v4-flash'),
        'name' => 'OpenRouter LLM',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $embeddingModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => (string) env('OPENROUTER_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
        'name' => 'OpenRouter Embedding',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $this->workspace->update([
        'knowledge_summary_model_id' => $summaryModel->id,
        'knowledge_embedding_model_id' => $embeddingModel->id,
        'knowledge_vector_index_enabled' => false,
        'knowledge_raptor_index_enabled' => true,
        'knowledge_chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
        'knowledge_chunk_max_tokens' => 60,
        'knowledge_chunk_overlap_tokens' => 0,
    ]);
    $knowledgeBase = KnowledgeBase::factory()->create([
    ]);
    // 内容刻意写到多段，让固定分段产生 ≥2 个叶子，RAPTOR 才会真正调外部 LLM 跑摘要。
    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $knowledgeBase->id,
        'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
        'parsed_content' => "# 产品说明\n\nHelmdesk 支持访客会话与工单流转。会话可被一键转工单，工单负责人需在服务等级协议时间内首响。"
            ."\n\n升级流程允许客服把工单转给主管，主管再转给值班经理。值班经理拥有跨工作区调度权限。"
            ."\n\nNPS 调查会在会话结束后自动发起。低于 4 分的评分会触发主管回访流程。"
            ."\n\n知识库支持向量、全文与 RAPTOR 三种索引。RAPTOR 适合长文档摘要式问答，向量适合语义相似匹配。"
            ."\n\n全文索引使用 SQLite FTS5，自带的中文分词器会对停用词做剔除。grep 检索则面向字面定位。"
            ."\n\nAgent 工具 knowledge_search 暴露三种 mode：grep / semantic / hybrid，由大模型按上下文选择。",
        'parsed_content_format' => 'markdown',
        'raptor_status' => KnowledgeDocumentIndexingStatus::Pending,
    ]);

    app(WriteCanonicalChunksAction::class)->forDocument($document);
    app(IndexKnowledgeDocumentRaptorAction::class)->handle($document);

    $document->refresh();
    $summaryNodes = KnowledgeNode::query()
        ->where('document_id', (string) $document->id)
        ->where('strategy', KnowledgeIndexingStrategy::Raptor->value)
        ->where('kind', KnowledgeNodeKind::Summary->value)
        ->get();

    expect($document->raptor_status)->toBe(KnowledgeDocumentIndexingStatus::Succeeded)
        ->and($summaryNodes->count())->toBeGreaterThan(0)
        ->and(mb_strlen(trim((string) $summaryNodes->first()->content)))->toBeGreaterThan(0);
});

function createLiveOpenRouterProvider(string $workspaceId): AiProvider
{
    return AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'kb-live-openrouter-'.Str::lower((string) Str::ulid()),
        'name' => 'Knowledge Runtime OpenRouter',
        'protocol' => AiProviderProtocol::OpenAI,
        'credentials' => [
            'key' => (string) env('OPENROUTER_API_KEY'),
            'base_uri' => 'https://openrouter.ai/api/v1',
        ],
        'credential_fields' => [
            ['field' => 'key', 'type' => 'password', 'required' => true],
            ['field' => 'base_uri', 'type' => 'url', 'required' => false],
        ],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
}
