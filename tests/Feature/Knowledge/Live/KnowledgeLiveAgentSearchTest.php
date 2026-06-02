<?php

use App\Actions\KnowledgeBase\Indexing\IndexKnowledgeDocumentRaptorAction;
use App\Actions\KnowledgeBase\Indexing\IndexKnowledgeDocumentVectorAction;
use App\Actions\KnowledgeBase\Indexing\WriteCanonicalChunksAction;
use App\Actions\KnowledgeBase\SearchKnowledgeBaseAction;
use App\Data\KnowledgeBase\FormKnowledgeSearchData;
use App\Enums\AiProviderProtocol;
use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeSearchMode;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

/*
 * Live：Agent 工具 knowledge_search 视角下的端到端打通。
 *
 * 工作区开 vector + raptor + rerank + 多 KB；用 SearchKnowledgeBaseAction 跑：
 *  - mode=grep    ：字面命中（不打外网）
 *  - mode=semantic：FTS + Vector + RAPTOR + 可选 rerank，全链路真实模型
 *  - mode=hybrid  ：semantic 与 grep 各跑一遍，返回两个数组
 *
 * 度量：每模式时延、命中数、命中结构关键字段，外部模型故障时 debug 里能看见原因。
 */

beforeEach(function (): void {
    if ((string) env('KNOWLEDGE_RUNTIME_LIVE') !== '1') {
        $this->markTestSkipped('Set KNOWLEDGE_RUNTIME_LIVE=1 to run the live agent search test.');
    }
    foreach (['GO_RUNTIME_BASE_URL', 'HELMDESK_INTERNAL_BRIDGE_TOKEN', 'OPENROUTER_API_KEY'] as $key) {
        if (blank(env($key))) {
            $this->markTestSkipped("Set {$key} to run the live agent search test.");
        }
    }

    $this->user = $this->createUserWithWorkspace();

    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'kb-agent-openrouter-'.Str::lower((string) Str::ulid()),
        'name' => 'Knowledge Agent OpenRouter',
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

    $embeddingModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => (string) env('OPENROUTER_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
        'name' => 'Live Embedding',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $llmModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => (string) env('OPENROUTER_LLM_MODEL', 'deepseek/deepseek-v4-flash'),
        'name' => 'Live LLM',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $rerankModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => (string) env('OPENROUTER_RERANK_MODEL', 'cohere/rerank-4-fast'),
        'name' => 'Live Rerank',
        'type' => 'rerank',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $this->workspace->update([
        'knowledge_embedding_model_id' => $embeddingModel->id,
        'knowledge_summary_model_id' => $llmModel->id,
        'knowledge_rerank_model_id' => $rerankModel->id,
        'knowledge_vector_index_enabled' => true,
        'knowledge_raptor_index_enabled' => true,
        'knowledge_chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
        'knowledge_chunk_max_tokens' => 200,
        'knowledge_chunk_overlap_tokens' => 24,
    ]);

    // 起两个知识库，验证 Agent 多 KB 入参的端到端行为。
    $this->kbProduct = KnowledgeBase::factory()->create([
        'name' => 'Helmdesk 产品文档',
    ]);
    $this->kbPolicy = KnowledgeBase::factory()->create([
        'name' => 'Helmdesk 服务条款',
    ]);

    $documents = [
        [
            'knowledge_base_id' => $this->kbProduct->id,
            'title' => '会话与工单',
            'content' => "# 会话与工单\n\nHelmdesk 支持把访客会话一键转工单。工单负责人会在 SLA 内完成首响。\n\n升级流程：客服可把工单升级给主管，主管再升级到值班经理。\n\n## 评价\n\n会话结束后可发起 NPS 调查；评分低于 4 触发回访。",
        ],
        [
            'knowledge_base_id' => $this->kbProduct->id,
            'title' => '知识库与问答',
            'content' => "# 知识库\n\nHelmdesk 支持向量、全文与 RAPTOR 三种索引。Agent 通过 knowledge_search 工具按需调用。\n\nRAPTOR 适合长文档摘要式问答；向量适合语义相似匹配；全文与 grep 适合精确字面定位。",
        ],
        [
            'knowledge_base_id' => $this->kbPolicy->id,
            'title' => '退款与发票',
            'content' => "# 退款政策\n\n标准订阅 7 天内可全额退款。超过 7 天但未启用的订阅按比例退款。\n\n## 发票\n\n开票需在订阅生效后 30 天内提交税号；逾期开票走单独的人工流程。",
        ],
        [
            'knowledge_base_id' => $this->kbPolicy->id,
            'title' => '隐私与数据',
            'content' => "# 隐私\n\nHelmdesk 仅在工作区范围内处理客户数据；数据出境需要工作区管理员单独授权。\n\n日志保留 180 天，会话录像保留 90 天，超期由后台批量销毁。",
        ],
    ];
    $this->documentIds = [];
    foreach ($documents as $entry) {
        $document = KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $entry['knowledge_base_id'],
            'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
            'parsed_content' => $entry['content'],
            'parsed_content_format' => 'markdown',
            'original_filename' => $entry['title'].'.md',
            'parse_metadata' => ['outline' => [['heading' => $entry['title'], 'level' => 1]]],
            'vector_status' => KnowledgeDocumentIndexingStatus::Pending,
            'raptor_status' => KnowledgeDocumentIndexingStatus::Pending,
        ]);
        app(WriteCanonicalChunksAction::class)->forDocument($document);
        $this->documentIds[] = (string) $document->id;
    }
});

test('Agent 视角下 knowledge_search 三种 mode 在真实模型上端到端跑通', function (): void {
    foreach (KnowledgeDocument::query()->whereIn('id', $this->documentIds)->get() as $document) {
        try {
            app(IndexKnowledgeDocumentVectorAction::class)->handle($document);
        } catch (Throwable $exception) {
            $this->markTestSkipped('Live embedding unavailable: '.$exception->getMessage());
        }
        try {
            app(IndexKnowledgeDocumentRaptorAction::class)->handle($document);
        } catch (Throwable $exception) {
            // RAPTOR 失败不影响其它模式的活体验证；记录到 STDERR。
            fwrite(STDERR, "[zh-agent-live] raptor build failed for {$document->id}: ".$exception->getMessage()."\n");
        }
    }

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);
    $kbIds = [(string) $this->kbProduct->id, (string) $this->kbPolicy->id];

    $cases = [
        ['mode' => KnowledgeSearchMode::Grep, 'query' => 'RAPTOR'],
        ['mode' => KnowledgeSearchMode::Semantic, 'query' => '客户对客服评分低于 4 分会发生什么？'],
        ['mode' => KnowledgeSearchMode::Hybrid, 'query' => ['退款政策有哪些限制？', '发票']],
    ];

    fwrite(STDERR, "\n[zh-agent-live] mode tour:\n");
    foreach ($cases as $case) {
        $start = microtime(true);
        $result = $action->handle($this->workspace, FormKnowledgeSearchData::from([
            'mode' => $case['mode']->value,
            'knowledge_base_ids' => $kbIds,
            'query' => $case['query'],
        ]));
        $elapsedMs = (microtime(true) - $start) * 1000;

        $semanticCount = count($result->semantic_hits);
        $grepCount = count($result->grep_matches);
        $debugSemantic = $result->debug['semantic'] ?? [];

        fwrite(STDERR, sprintf(
            "  mode=%-8s semantic=%-3d grep=%-3d latency=%7.1fms rerank_applied=%d embedding_error=%s\n",
            $case['mode']->value,
            $semanticCount,
            $grepCount,
            $elapsedMs,
            (int) ($debugSemantic['rerank_applied'] ?? 0),
            $debugSemantic['embedding_error'] ?? '-',
        ));

        expect($result->mode)->toBe($case['mode']->value);
        if ($case['mode'] === KnowledgeSearchMode::Grep) {
            expect($semanticCount)->toBe(0);
        } elseif ($case['mode'] === KnowledgeSearchMode::Semantic) {
            expect($grepCount)->toBe(0);
            // semantic 路径在所有外部依赖可用时应至少回一条命中；embedding 失败时也能走 FTS。
            expect($semanticCount)->toBeGreaterThan(0, 'semantic 模式至少应有一条命中');
        } else {
            expect($result->mode)->toBe(KnowledgeSearchMode::Hybrid->value);
        }
    }
});
