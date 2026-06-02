<?php

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
 * Live 中文召回基准测试：FTS / Vector / Hybrid / Hybrid+Rerank 四条流水线在
 * tests/Fixtures/Knowledge/zh_recall_corpus.json 上的 Recall@K / MRR@10 对比。
 *
 * 数据集结构遵循 DuReader-Retrieval (Baidu, EMNLP 2022) / C-MTEB 的最小集合表达；
 * 评测指标 Recall@K、MRR 与 BEIR / C-MTEB 主流榜单一致，便于横向参考。
 *
 * 开关：见 .env.example "Knowledge live integration / recall benchmark tests" 段。
 *
 * 注意：
 *  - 实测涉及 OpenRouter 真实 token 消耗；20 个查询 + 20 篇文档单次跑约 50-100 KB 文本。
 *  - rerank 当前仅支持 OpenRouter（/rerank 端点协议与 Cohere 一致）。
 */

beforeEach(function (): void {
    if ((string) env('KNOWLEDGE_RUNTIME_LIVE') !== '1') {
        $this->markTestSkipped('Set KNOWLEDGE_RUNTIME_LIVE=1 to run the live recall benchmark.');
    }
    foreach (['GO_RUNTIME_BASE_URL', 'HELMDESK_INTERNAL_BRIDGE_TOKEN', 'OPENROUTER_API_KEY'] as $key) {
        if (blank(env($key))) {
            $this->markTestSkipped("Set {$key} to run the live recall benchmark.");
        }
    }

    $this->user = $this->createUserWithWorkspace();

    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'kb-bench-openrouter-'.Str::lower((string) Str::ulid()),
        'name' => 'Knowledge Recall OpenRouter',
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

    $this->embeddingModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => (string) env('OPENROUTER_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
        'name' => 'Live Embedding',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    // 当前 rerank 仅走 OpenRouter，复用上面建的 provider。
    $this->rerankModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => (string) env('OPENROUTER_RERANK_MODEL', 'cohere/rerank-4-fast'),
        'name' => 'Live Rerank',
        'type' => 'rerank',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    $this->workspace->update([
        'knowledge_embedding_model_id' => $this->embeddingModel->id,
        'knowledge_rerank_model_id' => null,
        'knowledge_vector_index_enabled' => false,
        'knowledge_raptor_index_enabled' => false,
        'knowledge_chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
        'knowledge_chunk_max_tokens' => 220,
        'knowledge_chunk_overlap_tokens' => 32,
    ]);

    $this->kb = KnowledgeBase::factory()->create([
        'name' => '中文召回 Live 评测',
        'description' => 'tests/Fixtures/Knowledge/zh_recall_corpus.json',
    ]);

    $this->corpus = loadChineseRecallCorpus();
    $this->corpusDocumentIdByExternalId = [];
    foreach ($this->corpus['documents'] as $entry) {
        $document = KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $this->kb->id,
            'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
            'parsed_content' => $entry['content'],
            'parsed_content_format' => 'markdown',
            'original_filename' => $entry['title'].'.md',
            'parse_metadata' => ['outline' => [['heading' => $entry['title'], 'level' => 1]]],
            'vector_status' => KnowledgeDocumentIndexingStatus::Pending,
        ]);
        app(WriteCanonicalChunksAction::class)->forDocument($document);
        $this->corpusDocumentIdByExternalId[$entry['id']] = (string) $document->id;
    }
});

test('Live 中文召回基准对比 FTS / Hybrid / Hybrid+Rerank 三条流水线', function (): void {
    // 先把所有文档都打上真实向量；后续四条流水线共享同一份索引，只通过工作区开关切换走法。
    $vectorReady = true;
    $vectorError = null;
    $this->workspace->update(['knowledge_vector_index_enabled' => true]);
    foreach (KnowledgeDocument::query()->where('knowledge_base_id', $this->kb->id)->get() as $document) {
        try {
            app(IndexKnowledgeDocumentVectorAction::class)->handle($document);
        } catch (Throwable $exception) {
            $vectorReady = false;
            $vectorError = $exception->getMessage();
            break;
        }
    }

    if (! $vectorReady) {
        fwrite(STDERR, "[zh-recall-live] vector indexing failed: {$vectorError}\n");
        $this->markTestSkipped('Live embedding unavailable: '.$vectorError);
    }

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);

    // 三条流水线，依次只切换 workspace 配置；底层索引数据复用，不重复 embedding。
    // 注意：SearchKnowledgeBaseAction.semantic 永远会跑 FullTextRetriever，
    // 所以"Hybrid"实际等于"FTS + Vector"；纯 Vector 流水线无法在不改 Action 的前提下复刻。
    $pipelines = [
        'FTS only' => fn () => $this->workspace->update([
            'knowledge_vector_index_enabled' => false,
            'knowledge_rerank_model_id' => null,
        ]),
        'Hybrid (FTS+Vector)' => fn () => $this->workspace->update([
            'knowledge_vector_index_enabled' => true,
            'knowledge_rerank_model_id' => null,
        ]),
        'Hybrid + Rerank' => fn () => $this->workspace->update([
            'knowledge_vector_index_enabled' => true,
            'knowledge_rerank_model_id' => $this->rerankModel->id,
        ]),
    ];

    $report = [];
    foreach ($pipelines as $label => $configure) {
        $configure();
        $this->workspace->refresh();

        [$metrics, $latencyMs, $rerankApplied, $embeddingErrors] = collectMetricsForCurrentConfig(
            $action,
            $this->workspace,
            (string) $this->kb->id,
            $this->corpus['queries'],
            $this->corpusDocumentIdByExternalId,
        );

        $report[$label] = [
            'metrics' => $metrics,
            'avg_latency_ms' => $latencyMs,
            'rerank_applied' => $rerankApplied,
            'embedding_errors' => $embeddingErrors,
        ];
    }

    // 把整张对比表打到 STDERR，方便 CI 与本地直接抓数。
    $modelInfo = sprintf(
        "[zh-recall-live] embedding=%s rerank=%s queries=%d docs=%d\n",
        $this->embeddingModel->model_id,
        $this->rerankModel->model_id,
        count($this->corpus['queries']),
        count($this->corpus['documents']),
    );
    fwrite(STDERR, "\n".$modelInfo);
    fwrite(STDERR, sprintf("%-22s %8s %8s %8s %8s %10s %8s %8s\n",
        'pipeline', 'R@1', 'R@3', 'R@5', 'MRR@10', 'avg_ms', 'rerank', 'embed_err'));
    fwrite(STDERR, str_repeat('-', 94)."\n");
    foreach ($report as $label => $row) {
        fwrite(STDERR, sprintf("%-22s %8.4f %8.4f %8.4f %8.4f %10.1f %8d %8d\n",
            $label,
            $row['metrics']['recall@1'],
            $row['metrics']['recall@3'],
            $row['metrics']['recall@5'],
            $row['metrics']['mrr@10'],
            $row['avg_latency_ms'],
            $row['rerank_applied'],
            $row['embedding_errors'],
        ));
    }
    fwrite(STDERR, "\n");

    // 按 query type 拆解 MRR@10 与 Recall@5，看清每条流水线在不同难度上的真实贡献。
    $typesSeen = [];
    foreach ($this->corpus['queries'] as $query) {
        $typesSeen[$query['type']] = true;
    }
    $types = array_keys($typesSeen);
    sort($types);
    fwrite(STDERR, "[zh-recall-live] per-type MRR@10 / Recall@5:\n");
    fwrite(STDERR, sprintf('  %-16s', 'type \\ pipeline'));
    foreach (array_keys($report) as $label) {
        fwrite(STDERR, sprintf(' %-22s', $label));
    }
    fwrite(STDERR, "\n");
    foreach ($types as $type) {
        fwrite(STDERR, sprintf('  %-16s', $type));
        foreach ($report as $row) {
            $perType = $row['metrics']['per_type'][$type] ?? null;
            if ($perType === null) {
                fwrite(STDERR, sprintf(' %-22s', '-'));

                continue;
            }
            fwrite(STDERR, sprintf(' MRR=%.3f R@5=%.3f   ', $perType['mrr@10'], $perType['recall@5']));
        }
        fwrite(STDERR, "\n");
    }
    fwrite(STDERR, "\n");

    // per-query 详情仍然只打最强一档，方便定位个别中文查询是否回归。
    fwrite(STDERR, "[zh-recall-live] per-query best-MRR (Hybrid + Rerank):\n");
    foreach ($report['Hybrid + Rerank']['metrics']['per_query'] as $row) {
        fwrite(STDERR, sprintf("  %-6s %-14s mrr@10=%.4f  %s\n", $row['id'], '['.$row['type'].']', $row['mrr@10'], $row['text']));
    }

    // 硬性断言：所有流水线 MRR@10 不应低于 FTS 基线明显，且全部 >= 0.5。
    $ftsMrr = $report['FTS only']['metrics']['mrr@10'];
    foreach ($report as $label => $row) {
        expect($row['metrics']['mrr@10'])
            ->toBeGreaterThanOrEqual(0.5, "{$label} MRR@10 unexpectedly low");
    }
    expect($report['Hybrid (FTS+Vector)']['metrics']['mrr@10'])
        ->toBeGreaterThanOrEqual($ftsMrr - 0.05, 'Hybrid 不应明显劣于 FTS 基线');

    // Hybrid+Rerank：所有 20 个查询都应真正调通 rerank（OpenRouter /rerank 与 Cohere 同协议）。
    // 一旦上游退化为优雅降级，rerank_applied 就会是 0，这里能立刻发现。
    expect($report['Hybrid + Rerank']['embedding_errors'])
        ->toBe(0, 'Hybrid+Rerank 流水线发生了 embedding 错误，请检查 OpenRouter 配置');
    expect($report['Hybrid + Rerank']['rerank_applied'])
        ->toBe(count($this->corpus['queries']), 'Hybrid+Rerank 应对所有查询应用 rerank，疑似上游 /rerank 不通');
});

/**
 * 在当前 workspace 配置下跑一遍 SearchKnowledgeBaseAction 并采集指标。
 *
 * @param  list<array{id: string, text: string, positive_doc_ids: list<string>}>  $queries
 * @param  array<string, string>  $corpusDocumentIdByExternalId
 * @return array{0: array<string, mixed>, 1: float, 2: int, 3: int}
 */
function collectMetricsForCurrentConfig(
    SearchKnowledgeBaseAction $action,
    $workspace,
    string $knowledgeBaseId,
    array $queries,
    array $corpusDocumentIdByExternalId,
): array {
    $recallAt1Sum = 0.0;
    $recallAt3Sum = 0.0;
    $recallAt5Sum = 0.0;
    $mrrAt10Sum = 0.0;
    $perQuery = [];
    /** @var array<string, array{count: int, recall@1: float, recall@3: float, recall@5: float, mrr@10: float}> $perTypeSums */
    $perTypeSums = [];
    $latencies = [];
    $rerankApplied = 0;
    $embeddingErrors = 0;

    foreach ($queries as $query) {
        $start = microtime(true);
        $result = $action->handle($workspace, FormKnowledgeSearchData::from([
            'mode' => KnowledgeSearchMode::Semantic->value,
            'knowledge_base_ids' => [$knowledgeBaseId],
            'query' => [$query['text']],
        ]));
        $latencies[] = (microtime(true) - $start) * 1000;

        $semantic = $result->debug['semantic'] ?? [];
        if (! empty($semantic['rerank_applied'])) {
            $rerankApplied++;
        }
        if (isset($semantic['embedding_error'])) {
            $embeddingErrors++;
        }

        $retrievedDocIds = [];
        $seen = [];
        foreach ($result->semantic_hits as $hit) {
            $docId = $hit['document_id'] ?? null;
            if (! is_string($docId) || $docId === '' || isset($seen[$docId])) {
                continue;
            }
            $seen[$docId] = true;
            $retrievedDocIds[] = $docId;
        }

        $positiveDocIds = array_values(array_filter(array_map(
            static fn (string $externalId): ?string => $corpusDocumentIdByExternalId[$externalId] ?? null,
            $query['positive_doc_ids'],
        )));

        $recallAt1 = recallAtK($retrievedDocIds, $positiveDocIds, 1);
        $recallAt3 = recallAtK($retrievedDocIds, $positiveDocIds, 3);
        $recallAt5 = recallAtK($retrievedDocIds, $positiveDocIds, 5);
        $mrrAt10 = reciprocalRankAtK($retrievedDocIds, $positiveDocIds, 10);

        $type = (string) ($query['type'] ?? 'unknown');
        $perQuery[] = [
            'id' => $query['id'],
            'type' => $type,
            'text' => $query['text'],
            'recall@1' => $recallAt1,
            'recall@3' => $recallAt3,
            'recall@5' => $recallAt5,
            'mrr@10' => $mrrAt10,
        ];

        if (! isset($perTypeSums[$type])) {
            $perTypeSums[$type] = ['count' => 0, 'recall@1' => 0.0, 'recall@3' => 0.0, 'recall@5' => 0.0, 'mrr@10' => 0.0];
        }
        $perTypeSums[$type]['count']++;
        $perTypeSums[$type]['recall@1'] += $recallAt1;
        $perTypeSums[$type]['recall@3'] += $recallAt3;
        $perTypeSums[$type]['recall@5'] += $recallAt5;
        $perTypeSums[$type]['mrr@10'] += $mrrAt10;

        $recallAt1Sum += $recallAt1;
        $recallAt3Sum += $recallAt3;
        $recallAt5Sum += $recallAt5;
        $mrrAt10Sum += $mrrAt10;
    }

    $total = count($queries);

    $perType = [];
    foreach ($perTypeSums as $type => $sums) {
        $n = max(1, $sums['count']);
        $perType[$type] = [
            'count' => $sums['count'],
            'recall@1' => round($sums['recall@1'] / $n, 4),
            'recall@3' => round($sums['recall@3'] / $n, 4),
            'recall@5' => round($sums['recall@5'] / $n, 4),
            'mrr@10' => round($sums['mrr@10'] / $n, 4),
        ];
    }

    return [
        [
            'recall@1' => round($recallAt1Sum / $total, 4),
            'recall@3' => round($recallAt3Sum / $total, 4),
            'recall@5' => round($recallAt5Sum / $total, 4),
            'mrr@10' => round($mrrAt10Sum / $total, 4),
            'per_query' => $perQuery,
            'per_type' => $perType,
        ],
        array_sum($latencies) / max(1, count($latencies)),
        $rerankApplied,
        $embeddingErrors,
    ];
}
