<?php

use App\Actions\KnowledgeBase\Indexing\WriteCanonicalChunksAction;
use App\Actions\KnowledgeBase\SearchKnowledgeBaseAction;
use App\Data\KnowledgeBase\FormKnowledgeSearchData;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeSearchMode;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Services\KnowledgeBase\Search\FullTextRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

/*
 * 中文召回评测：lexical 基线（FullTextRetriever + SearchKnowledgeBaseAction，不开向量 / RAPTOR）。
 *
 * 评测集 tests/Fixtures/Knowledge/zh_recall_corpus.json 结构遵循 DuReader-Retrieval (Baidu, EMNLP 2022)
 * 与 C-MTEB 的 CovidRetrieval / T2Retrieval；指标 Recall@K、MRR 与 BEIR / C-MTEB 一致。
 *
 * 共享 helper（loadChineseRecallCorpus / recallAtK / reciprocalRankAtK）在
 * tests/Support/KnowledgeRecallHelpers.php，由 tests/Pest.php require_once 引入。
 */

beforeEach(function (): void {
    $this->user = $this->createUserWithSystem();
    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'kb-recall-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Recall Provider',
        'protocol' => 'openai',
        'credential_fields' => [],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->embeddingModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'kb-recall-embedding-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Recall Embedding',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->systemContext->update([
        'knowledge_embedding_model_id' => $this->embeddingModel->id,
        // 中文 FTS 召回是本次评测的主要测试面，向量与 RAPTOR 需要外部模型，先关掉。
        'knowledge_vector_index_enabled' => false,
        'knowledge_raptor_index_enabled' => false,
    ]);

    $this->kb = KnowledgeBase::factory()->create([
        'name' => '中文召回评测知识库',
        'description' => '加载自 tests/Fixtures/Knowledge/zh_recall_corpus.json',
    ]);

    $this->corpusDocumentIdByExternalId = [];
    foreach (loadChineseRecallCorpus()['documents'] as $entry) {
        $document = KnowledgeDocument::factory()->create([
            'knowledge_base_id' => $this->kb->id,
            'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
            'parsed_content' => $entry['content'],
            'parsed_content_format' => 'markdown',
            'original_filename' => $entry['title'].'.md',
            'parse_metadata' => ['outline' => [['heading' => $entry['title'], 'level' => 1]]],
        ]);
        app(WriteCanonicalChunksAction::class)->forDocument($document);
        $this->corpusDocumentIdByExternalId[$entry['id']] = (string) $document->id;
    }
});

test('FullTextRetriever 在中文评测集上 Recall@5 与 MRR@10 达到合格阈值', function (): void {
    $corpus = loadChineseRecallCorpus();
    /** @var FullTextRetriever $retriever */
    $retriever = app(FullTextRetriever::class);

    $recallAt1Sum = 0.0;
    $recallAt3Sum = 0.0;
    $recallAt5Sum = 0.0;
    $mrrAt10Sum = 0.0;
    $perQuery = [];

    foreach ($corpus['queries'] as $query) {
        $hits = $retriever->retrieve(
            knowledgeBaseIds: [(string) $this->kb->id],
            queries: [$query['text']],
            topK: 10,
        );
        $retrievedDocIds = [];
        $seen = [];
        foreach ($hits as $hit) {
            $docId = $hit->documentId;
            if ($docId === null || isset($seen[$docId])) {
                continue;
            }
            $seen[$docId] = true;
            $retrievedDocIds[] = $docId;
        }

        $positiveDocIds = array_values(array_filter(array_map(
            fn (string $externalId): ?string => $this->corpusDocumentIdByExternalId[$externalId] ?? null,
            $query['positive_doc_ids'],
        )));

        $recallAt1 = recallAtK($retrievedDocIds, $positiveDocIds, 1);
        $recallAt3 = recallAtK($retrievedDocIds, $positiveDocIds, 3);
        $recallAt5 = recallAtK($retrievedDocIds, $positiveDocIds, 5);
        $mrrAt10 = reciprocalRankAtK($retrievedDocIds, $positiveDocIds, 10);

        $perQuery[] = [
            'id' => $query['id'],
            'text' => $query['text'],
            'recall@1' => $recallAt1,
            'recall@3' => $recallAt3,
            'recall@5' => $recallAt5,
            'mrr@10' => $mrrAt10,
        ];

        $recallAt1Sum += $recallAt1;
        $recallAt3Sum += $recallAt3;
        $recallAt5Sum += $recallAt5;
        $mrrAt10Sum += $mrrAt10;
    }

    $total = count($corpus['queries']);
    $metrics = [
        'recall@1' => round($recallAt1Sum / $total, 4),
        'recall@3' => round($recallAt3Sum / $total, 4),
        'recall@5' => round($recallAt5Sum / $total, 4),
        'mrr@10' => round($mrrAt10Sum / $total, 4),
        'per_query' => $perQuery,
    ];

    // 把详细指标输出到 stderr，便于 CI / 本地排查；不影响测试断言。
    fwrite(STDERR, sprintf(
        "[zh-recall-mini] FullTextRetriever metrics: recall@1=%.4f recall@3=%.4f recall@5=%.4f mrr@10=%.4f (queries=%d)\n",
        $metrics['recall@1'],
        $metrics['recall@3'],
        $metrics['recall@5'],
        $metrics['mrr@10'],
        $total,
    ));

    // 阈值参考 BEIR / C-MTEB 在轻量 lexical 基线（BM25 + 分词）上的常见区间，
    // 评测集偏小、问题与正例段落字面重合较高，把 recall@5 卡到 0.85 是合理基线。
    expect($metrics['recall@1'])->toBeGreaterThanOrEqual(0.7)
        ->and($metrics['recall@3'])->toBeGreaterThanOrEqual(0.8)
        ->and($metrics['recall@5'])->toBeGreaterThanOrEqual(0.85)
        ->and($metrics['mrr@10'])->toBeGreaterThanOrEqual(0.75);
});

test('SearchKnowledgeBaseAction semantic 模式在中文评测集上的整体召回不低于纯 FTS 基线', function (): void {
    // 系统当前未启用向量索引，semantic 模式实际只跑 FullTextRetriever；
    // 这个用例保证 SearchKnowledgeBaseAction 在整条 RRF + ContextExpander 流水线下的 Recall@5
    // 不会比裸 FullTextRetriever 更差，从而捕捉到融合 / 截断 / 上下文扩展环节的回归。
    $corpus = loadChineseRecallCorpus();
    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);

    $recallAt5Sum = 0.0;
    foreach ($corpus['queries'] as $query) {
        $result = $action->handle($this->systemContext, FormKnowledgeSearchData::from([
            'mode' => KnowledgeSearchMode::Semantic->value,
            'knowledge_base_ids' => [(string) $this->kb->id],
            'query' => [$query['text']],
        ]));

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
            fn (string $externalId): ?string => $this->corpusDocumentIdByExternalId[$externalId] ?? null,
            $query['positive_doc_ids'],
        )));
        $recallAt5Sum += recallAtK($retrievedDocIds, $positiveDocIds, 5);
    }

    $recallAt5 = $recallAt5Sum / count($corpus['queries']);
    expect($recallAt5)->toBeGreaterThanOrEqual(0.85);
});

test('GrepRetriever 在全语料 + 多查询类型下的字面召回、误报与位置精度都达标', function (): void {
    // 类 grep 检索不依赖外部模型，全部是 SQL LIKE + PHP 字节级搜索。
    // 这条评测把整个 40 条 query × 57 篇文档的语料过一遍，按 query type 拆解四个指标：
    //  - oracle_should_hit: query 是否在任意 positive 文档里字面出现（决定该 query 是否应该被 grep 命中）
    //  - literal_recall: 在 oracle_should_hit 的子集中，grep 是否真的把 positive 文档召回了
    //  - false_positive_rate: grep 命中里有多少是 corpus 标注以外的"无辜文档"
    //  - position_accuracy: 每条 grep_match 的 (byte_start, byte_end) 切片是否真的等于 query
    $corpus = loadChineseRecallCorpus();
    $contentByExternalId = collect($corpus['documents'])
        ->mapWithKeys(fn (array $doc): array => [$doc['id'] => (string) $doc['content']])
        ->all();

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);

    /** @var array<string, array{queries: int, oracle: int, recall_hits: int, fp_docs: int, total_hits: int}> $byType */
    $byType = [];
    $totalPositionChecks = 0;
    $positionFailures = [];
    $oracleQueries = 0;
    $oracleRecallHits = 0;
    $hardFailures = [];

    foreach ($corpus['queries'] as $query) {
        $type = (string) ($query['type'] ?? 'unknown');
        if (! isset($byType[$type])) {
            $byType[$type] = ['queries' => 0, 'oracle' => 0, 'recall_hits' => 0, 'fp_docs' => 0, 'total_hits' => 0];
        }
        $byType[$type]['queries']++;

        $positiveExternalIds = $query['positive_doc_ids'];
        $positiveDbIds = array_values(array_filter(array_map(
            fn (string $external): ?string => $this->corpusDocumentIdByExternalId[$external] ?? null,
            $positiveExternalIds,
        )));

        $needle = mb_strtolower($query['text']);
        $oracleHitDocs = [];
        foreach ($positiveExternalIds as $externalId) {
            $haystack = $contentByExternalId[$externalId] ?? '';
            if ($needle !== '' && mb_strpos(mb_strtolower($haystack), $needle) !== false) {
                $oracleHitDocs[] = $externalId;
            }
        }
        $shouldHit = $oracleHitDocs !== [];
        if ($shouldHit) {
            $oracleQueries++;
            $byType[$type]['oracle']++;
        }

        $result = $action->handle($this->systemContext, FormKnowledgeSearchData::from([
            'mode' => KnowledgeSearchMode::Grep->value,
            'knowledge_base_ids' => [(string) $this->kb->id],
            'query' => $query['text'],
        ]));

        $hitDocIds = [];
        foreach ($result->grep_matches as $match) {
            $docId = $match['document_id'] ?? null;
            if (is_string($docId) && $docId !== '') {
                $hitDocIds[$docId] = true;
            }

            // 位置精度断言：用 byte_start / byte_end 在原文上切回去，必须等于 query（大小写无关）。
            if (is_string($docId) && $docId !== '') {
                $externalIdForCheck = array_search($docId, $this->corpusDocumentIdByExternalId, true);
                if ($externalIdForCheck !== false) {
                    $totalPositionChecks++;
                    $original = $contentByExternalId[$externalIdForCheck] ?? '';
                    $slice = substr($original, (int) $match['byte_start'], (int) $match['byte_end'] - (int) $match['byte_start']);
                    if (mb_strtolower($slice) !== mb_strtolower($query['text'])) {
                        $positionFailures[] = sprintf(
                            'query=%s doc=%s byte_range=[%d,%d) got=%s',
                            $query['text'],
                            $externalIdForCheck,
                            (int) $match['byte_start'],
                            (int) $match['byte_end'],
                            $slice,
                        );
                    }
                }
            }
        }
        $hitDocIds = array_keys($hitDocIds);

        $hitPositive = array_values(array_intersect($hitDocIds, $positiveDbIds));
        $hitFalsePositive = array_values(array_diff($hitDocIds, $positiveDbIds));

        $byType[$type]['total_hits'] += count($hitDocIds);
        $byType[$type]['fp_docs'] += count($hitFalsePositive);
        if ($shouldHit && $hitPositive !== []) {
            $byType[$type]['recall_hits']++;
            $oracleRecallHits++;
        } elseif ($shouldHit) {
            // 字面真的在 positive 里出现却没被 grep 命中——这才是 grep 的硬故障。
            $hardFailures[] = sprintf('query=%s expected_in=%s', $query['text'], implode(',', $oracleHitDocs));
        }
    }

    $oracleRecall = $oracleQueries > 0 ? $oracleRecallHits / $oracleQueries : 0.0;

    // 输出指标矩阵到 stderr。
    fwrite(STDERR, sprintf(
        "\n[zh-grep-mini] queries=%d oracle_should_hit=%d literal_recall=%.4f position_checks=%d position_ok=%d\n",
        count($corpus['queries']),
        $oracleQueries,
        $oracleRecall,
        $totalPositionChecks,
        $totalPositionChecks - count($positionFailures),
    ));
    fwrite(STDERR, sprintf("  %-16s %8s %8s %8s %10s %10s\n", 'type', 'queries', 'oracle', 'recall', 'tot_hits', 'fp_docs'));
    fwrite(STDERR, '  '.str_repeat('-', 66)."\n");
    foreach ($byType as $type => $row) {
        $recall = $row['oracle'] > 0 ? $row['recall_hits'] / $row['oracle'] : 0.0;
        fwrite(STDERR, sprintf(
            "  %-16s %8d %8d %8.4f %10d %10d\n",
            $type,
            $row['queries'],
            $row['oracle'],
            $recall,
            $row['total_hits'],
            $row['fp_docs'],
        ));
    }

    // 断言（grep 是确定性的字面 SQL LIKE，所以这两条都必须 100% 通过）：
    //  - 凡是 query 的字面真的出现在某个 positive 文档里，grep 必须召回该正例（hardFailures 覆盖此约束）。
    //  - 所有 grep_match 的字节范围都必须能切回 query 自身，否则前端高亮会错位。
    //  - oracleRecall 在 oracleQueries > 0 时应当 = 1.0；自然语言查询很少与正例完全字面重合，
    //    oracleQueries 在本语料上可能为 0，这反而印证了 grep 与语义检索的职责分工。
    expect($hardFailures)->toBe([], '凡是字面真的在 positive 里出现，grep 必须召回该正例: '.implode(' | ', $hardFailures))
        ->and($positionFailures)->toBe([], 'byte_start/byte_end 必须切回 query: '.implode(' | ', $positionFailures));
    if ($oracleQueries > 0) {
        expect($oracleRecall)->toBe(1.0);
    }
});

test('GrepRetriever 在随机抽取的中文短语上保持 100% 字面召回与位置精度', function (): void {
    // 这条用例做的是"逆向覆盖"：直接从 corpus 文档中抽出确定存在的中文短语，
    // 然后把它喂给 grep，必须能命中源文档（验证 SQL LIKE 与 PHP 字节扫描在边界字符上的正确性）。
    $corpus = loadChineseRecallCorpus();
    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);

    $cases = [];
    foreach ($corpus['documents'] as $doc) {
        $content = (string) $doc['content'];
        $chars = preg_split('//u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($chars) < 30) {
            continue;
        }
        // 抽 3 个长度在 [4, 8] 之间的纯中文短语，避开 Markdown 标题与换行。
        for ($attempt = 0, $picked = 0; $attempt < 20 && $picked < 3; $attempt++) {
            $length = random_int(4, 8);
            $start = random_int(5, count($chars) - $length - 1);
            $phrase = implode('', array_slice($chars, $start, $length));
            // 用 \A...\z 严格框住整个字符串（默认的 ^/$ 会允许结尾换行，导致 "etMQ\n" 这种短语逃过过滤）。
            if (! preg_match('/\A[\x{4e00}-\x{9fff}0-9A-Za-z]+\z/u', $phrase)) {
                continue;
            }
            $cases[] = ['phrase' => $phrase, 'expect_external_id' => $doc['id']];
            $picked++;
        }
    }

    $hardFailures = [];
    $positionFailures = [];
    foreach ($cases as $case) {
        $result = $action->handle($this->systemContext, FormKnowledgeSearchData::from([
            'mode' => KnowledgeSearchMode::Grep->value,
            'knowledge_base_ids' => [(string) $this->kb->id],
            'query' => $case['phrase'],
        ]));
        $expectedDocId = $this->corpusDocumentIdByExternalId[$case['expect_external_id']];
        $hitDocIds = array_unique(array_filter(array_map(
            static fn (array $match) => $match['document_id'] ?? null,
            $result->grep_matches,
        )));
        if (! in_array($expectedDocId, $hitDocIds, true)) {
            $hardFailures[] = sprintf('phrase="%s" expected_doc=%s', $case['phrase'], $case['expect_external_id']);

            continue;
        }
        foreach ($result->grep_matches as $match) {
            if (($match['document_id'] ?? null) !== $expectedDocId) {
                continue;
            }
            $externalId = $case['expect_external_id'];
            $originalContent = collect($corpus['documents'])->firstWhere('id', $externalId)['content'] ?? '';
            $slice = substr($originalContent, (int) $match['byte_start'], (int) $match['byte_end'] - (int) $match['byte_start']);
            if (mb_strtolower($slice) !== mb_strtolower($case['phrase'])) {
                $positionFailures[] = sprintf('phrase="%s" doc=%s slice="%s"', $case['phrase'], $externalId, $slice);
            }
        }
    }

    fwrite(STDERR, sprintf(
        "[zh-grep-random] phrases=%d hard_failures=%d position_failures=%d\n",
        count($cases),
        count($hardFailures),
        count($positionFailures),
    ));

    expect(count($cases))->toBeGreaterThan(50, '应至少抽到 50 个中文短语');
    expect($hardFailures)->toBe([], '随机抽取的中文短语必须 100% 命中源文档: '.implode(' | ', $hardFailures));
    expect($positionFailures)->toBe([], 'byte_start/byte_end 必须切回 phrase: '.implode(' | ', $positionFailures));
});

test('GrepRetriever 在多 query 数组输入下能聚合多组字面命中', function (): void {
    // Agent 调用 knowledge_search 时 query 允许为数组，grep 需要能同时跑多条字面短语并把命中合并。
    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);
    $result = $action->handle($this->systemContext, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Grep->value,
        'knowledge_base_ids' => [(string) $this->kb->id],
        'query' => ['HTTPS', '太阳花', '兵马俑'],
    ]));

    $hitExternalIds = [];
    foreach ($result->grep_matches as $match) {
        $docId = $match['document_id'] ?? null;
        if (! is_string($docId) || $docId === '') {
            continue;
        }
        $externalId = array_search($docId, $this->corpusDocumentIdByExternalId, true);
        if ($externalId !== false) {
            $hitExternalIds[$externalId] = true;
        }
    }
    $hitExternalIds = array_keys($hitExternalIds);

    expect($hitExternalIds)->toContain('doc_http_vs_https')
        ->and($hitExternalIds)->toContain('doc_sunflower_care')
        ->and($hitExternalIds)->toContain('doc_terracotta');
});
