<?php

use App\Actions\KnowledgeBase\Indexing\WriteCanonicalChunksAction;
use App\Actions\KnowledgeBase\Qa\CreateKnowledgeQaEntryAction;
use App\Actions\KnowledgeBase\SearchKnowledgeBaseAction;
use App\Actions\Native\Knowledge\KnowledgeSearchBridgeAction;
use App\Data\KnowledgeBase\FormCreateKnowledgeQaEntryData;
use App\Data\KnowledgeBase\FormKnowledgeSearchData;
use App\Enums\KnowledgeBaseCategory;
use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeSearchMode;
use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeQaEntry;
use App\Models\Workspace;
use App\Services\KnowledgeBase\GoKnowledgeBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function (): void {
    $this->user = $this->createUserWithWorkspace();
    $provider = AiProvider::query()->create([
        'workspace_id' => $this->workspace->id,
        'brand' => 'custom-openai',
        'slug' => 'kb-search-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Search Provider',
        'protocol' => 'openai',
        'credential_fields' => [],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->embeddingModel = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'kb-search-embedding-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Search Embedding',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->workspace->update([
        'knowledge_embedding_model_id' => $this->embeddingModel->id,
        'knowledge_vector_index_enabled' => false,
        'knowledge_raptor_index_enabled' => false,
    ]);
    $this->kb = KnowledgeBase::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => '测试知识库',
        'description' => '存放助手测试用的内容',
    ]);
});

/**
 * 写一篇已经"解析成功 + canonical 节点 + FTS"的文档，跳过真实解析阶段，专心测召回。
 */
function seedSearchableDocument(KnowledgeBase $kb, string $body): KnowledgeDocument
{
    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $kb->id,
        'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
        'parsed_content' => $body,
        'parsed_content_format' => 'markdown',
        'parse_metadata' => ['outline' => [['heading' => '产品手册', 'level' => 1]]],
        'vector_status' => KnowledgeDocumentIndexingStatus::Idle,
    ]);
    app(WriteCanonicalChunksAction::class)->forDocument($document);

    return $document;
}

test('FormKnowledgeSearchData 把 query 数组截断到 MAX_QUERIES 条', function (): void {
    $queries = [];
    for ($i = 0; $i < 20; $i++) {
        $queries[] = 'q'.$i;
    }
    $data = FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Semantic->value,
        'knowledge_base_ids' => [(string) $this->kb->id],
        'query' => $queries,
    ]);

    expect(count($data->normalizedQueries()))->toBe(FormKnowledgeSearchData::MAX_QUERIES);
});

test('FormKnowledgeSearchData 把单条 query 截断到 MAX_QUERY_LENGTH 字符', function (): void {
    $longQuery = str_repeat('中', FormKnowledgeSearchData::MAX_QUERY_LENGTH + 50);
    $data = FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Semantic->value,
        'knowledge_base_ids' => [(string) $this->kb->id],
        'query' => $longQuery,
    ]);

    $normalized = $data->normalizedQueries();
    expect(count($normalized))->toBe(1)
        ->and(mb_strlen($normalized[0]))->toBe(FormKnowledgeSearchData::MAX_QUERY_LENGTH);
});

test('SearchKnowledgeBaseAction grep 模式返回带 line 与 byte_offset 的字面命中', function (): void {
    $document = seedSearchableDocument(
        $this->kb,
        "# 产品手册\n\n这里介绍 Helmdesk 的工单流程。Helmdesk 同时支持知识库问答。",
    );

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);
    $result = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Grep->value,
        'knowledge_base_ids' => [(string) $this->kb->id],
        'query' => 'Helmdesk',
    ]));

    expect($result->mode)->toBe('grep')
        ->and($result->semantic_hits)->toBe([])
        ->and($result->grep_matches)->not->toBeEmpty();

    $first = $result->grep_matches[0];
    expect($first['document_id'])->toBe((string) $document->id)
        ->and($first['query'])->toBe('Helmdesk')
        ->and($first['line'])->toBeGreaterThan(0)
        ->and($first['byte_start'])->toBeGreaterThanOrEqual(0)
        ->and($first['match'])->toBe('Helmdesk');
});

test('SearchKnowledgeBaseAction 在未传知识库 ID 时检索当前工作区全部知识库', function (): void {
    $otherKb = KnowledgeBase::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => '补充资料库',
    ]);

    seedSearchableDocument(
        $this->kb,
        "# 产品手册\n\n这里介绍 Helmdesk 的工单流程。",
    );
    $otherDocument = seedSearchableDocument(
        $otherKb,
        "# 人员资料\n\n王进华负责知识库检索联调。",
    );

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);
    $result = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Grep->value,
        'query' => '王进华',
    ]));

    expect($result->mode)->toBe('grep')
        ->and($result->grep_matches)->not->toBeEmpty()
        ->and($result->grep_matches[0]['document_id'])->toBe((string) $otherDocument->id)
        ->and($result->debug['knowledge_base_ids'])->toContain((string) $this->kb->id, (string) $otherKb->id);
});

test('SearchKnowledgeBaseAction semantic 模式至少能走通全文检索并通过 RRF 融合', function (): void {
    seedSearchableDocument(
        $this->kb,
        "# 产品手册\n\n工单流程包含创建、流转、关闭三个阶段。\n\n知识库问答支持上传 Markdown 文件。",
    );

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);
    $result = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Semantic->value,
        'knowledge_base_ids' => [(string) $this->kb->id],
        'query' => ['工单'],
    ]));

    expect($result->mode)->toBe('semantic')
        ->and($result->grep_matches)->toBe([])
        ->and($result->semantic_hits)->not->toBeEmpty()
        ->and($result->debug['semantic']['fulltext_enabled'] ?? null)->toBeTrue()
        ->and($result->debug['semantic']['vector_enabled'] ?? null)->toBeFalse()
        ->and($result->debug['semantic']['raptor_enabled'] ?? null)->toBeFalse()
        ->and($result->debug['semantic']['rerank_enabled'] ?? null)->toBeFalse();
});

test('SearchKnowledgeBaseAction hybrid 模式同时返回语义与 grep 结果', function (): void {
    seedSearchableDocument(
        $this->kb,
        "# 产品手册\n\nHelmdesk 提供工单流转。\n\n知识库问答覆盖常见问题。",
    );

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);
    $result = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Hybrid->value,
        'knowledge_base_ids' => [(string) $this->kb->id],
        'query' => '工单',
    ]));

    expect($result->mode)->toBe('hybrid')
        ->and($result->semantic_hits)->not->toBeEmpty()
        ->and($result->grep_matches)->not->toBeEmpty();
});

test('SearchKnowledgeBaseAction 拒绝当前工作区之外的知识库 ID', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    $otherKb = KnowledgeBase::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);

    expect(fn () => $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Grep->value,
        'knowledge_base_ids' => [(string) $otherKb->id],
        'query' => 'whatever',
    ])))->toThrow(BusinessException::class);
});

test('SearchKnowledgeBaseAction 对空 query 抛业务异常', function (): void {
    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);

    expect(fn () => $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Grep->value,
        'knowledge_base_ids' => [(string) $this->kb->id],
        'query' => ['   '],
    ])))->toThrow(BusinessException::class);
});

test('SearchKnowledgeBaseAction 在向量嵌入失败时仅走全文召回并在 debug 中记录错误', function (): void {
    seedSearchableDocument(
        $this->kb,
        "# 产品手册\n\n服务台需要的关键能力包括工单流转、知识库问答与多渠道接入。",
    );

    $this->workspace->update([
        'knowledge_vector_index_enabled' => true,
    ]);

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);
    $result = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Semantic->value,
        'knowledge_base_ids' => [(string) $this->kb->id],
        'query' => '工单',
    ]));

    expect($result->mode)->toBe('semantic')
        ->and($result->semantic_hits)->not->toBeEmpty()
        ->and($result->debug['semantic']['vector_enabled'] ?? null)->toBeFalse()
        ->and($result->debug['semantic']['embedding_error'] ?? null)->not->toBeNull();
});

test('KnowledgeSearchBridgeAction 把 Go 透传的 4 个参数转发到业务 Action', function (): void {
    seedSearchableDocument(
        $this->kb,
        "# 产品手册\n\nHelmdesk 工单流程。",
    );

    /** @var KnowledgeSearchBridgeAction $bridge */
    $bridge = app(KnowledgeSearchBridgeAction::class);
    $result = $bridge->handle(
        workspaceId: (string) $this->workspace->id,
        mode: 'grep',
        knowledgeBaseIds: [(string) $this->kb->id],
        queries: ['Helmdesk'],
    );

    expect($result->mode)->toBe('grep')
        ->and(count($result->grep_matches))->toBeGreaterThan(0);
});

test('KnowledgeSearchBridgeAction 拒绝未知 workspace 与未知 mode', function (): void {
    /** @var KnowledgeSearchBridgeAction $bridge */
    $bridge = app(KnowledgeSearchBridgeAction::class);

    expect(fn () => $bridge->handle('00000000000000000000000000', 'grep', [(string) $this->kb->id], 'foo'))
        ->toThrow(BusinessException::class);

    expect(fn () => $bridge->handle((string) $this->workspace->id, 'unsupported', [(string) $this->kb->id], 'foo'))
        ->toThrow(BusinessException::class);
});

/**
 * 创建一条问答条目并跑完 canonical / FTS 流水线，返回写好的 Entry。
 */
function seedQaEntry(KnowledgeBase $qaKb, string $question, array $similar, array $answers): KnowledgeQaEntry
{
    return app(CreateKnowledgeQaEntryAction::class)->handle(
        $qaKb,
        FormCreateKnowledgeQaEntryData::from([
            'question' => $question,
            'similar_questions' => $similar,
            'answers' => $answers,
        ]),
    );
}

test('SearchKnowledgeBaseAction 在 QA 知识库上：主问题、相似问、答案都能被 FTS 召回', function (): void {
    $qaKb = KnowledgeBase::factory()->create([
        'workspace_id' => $this->workspace->id,
        'category' => KnowledgeBaseCategory::Qa->value,
        'name' => '产品问答库',
    ]);
    $entry = seedQaEntry(
        $qaKb,
        '如何申请退款？',
        ['怎么退款？', '退款入口在哪里？'],
        ['请在订单详情页提交退款申请。', '企业客户可以联系客户成功经理。'],
    );

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);

    // 主问题中的关键词必须命中。
    $primary = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Semantic->value,
        'knowledge_base_ids' => [(string) $qaKb->id],
        'query' => ['申请退款'],
    ]));
    expect($primary->semantic_hits)->not->toBeEmpty();
    $primaryEntryIds = array_unique(array_filter(array_map(
        static fn (array $hit) => $hit['qa_entry_id'] ?? null,
        $primary->semantic_hits,
    )));
    expect($primaryEntryIds)->toContain((string) $entry->id);

    // 仅出现在相似问中的措辞同样要能命中相同 entry。
    $similar = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Semantic->value,
        'knowledge_base_ids' => [(string) $qaKb->id],
        'query' => ['退款入口'],
    ]));
    $similarEntryIds = array_unique(array_filter(array_map(
        static fn (array $hit) => $hit['qa_entry_id'] ?? null,
        $similar->semantic_hits,
    )));
    expect($similarEntryIds)->toContain((string) $entry->id);

    // 仅出现在答案中的措辞同样要能命中相同 entry。
    $answer = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Semantic->value,
        'knowledge_base_ids' => [(string) $qaKb->id],
        'query' => ['客户成功经理'],
    ]));
    $answerEntryIds = array_unique(array_filter(array_map(
        static fn (array $hit) => $hit['qa_entry_id'] ?? null,
        $answer->semantic_hits,
    )));
    expect($answerEntryIds)->toContain((string) $entry->id);
});

test('SearchKnowledgeBaseAction 命中 QA 节点时 metadata.context.qa 带回完整问答结构', function (): void {
    $qaKb = KnowledgeBase::factory()->create([
        'workspace_id' => $this->workspace->id,
        'category' => KnowledgeBaseCategory::Qa->value,
    ]);
    $entry = seedQaEntry(
        $qaKb,
        '产品支持哪些导出格式？',
        ['可以导出哪些格式？'],
        ['支持导出 CSV、Excel 与 PDF 三种格式。', '企业版还可以导出 JSON 与 Parquet。'],
    );

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);
    $result = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Semantic->value,
        'knowledge_base_ids' => [(string) $qaKb->id],
        'query' => ['导出格式'],
    ]));

    $matched = collect($result->semantic_hits)->first(static fn (array $hit) => ($hit['qa_entry_id'] ?? null) === (string) $entry->id);
    expect($matched)->not->toBeNull();
    $qa = $matched['metadata']['context']['qa'] ?? null;
    expect($qa)->toBeArray()
        ->and($qa['entry_id'] ?? null)->toBe((string) $entry->id)
        ->and($qa['primary_question'] ?? null)->toBe('产品支持哪些导出格式？');

    $answerContents = array_map(static fn (array $a) => $a['content'] ?? null, $qa['answers'] ?? []);
    expect($answerContents)->toContain('支持导出 CSV、Excel 与 PDF 三种格式。')
        ->and($answerContents)->toContain('企业版还可以导出 JSON 与 Parquet。');

    $similarContents = array_map(static fn (array $q) => $q['content'] ?? null, $qa['similar_questions'] ?? []);
    expect($similarContents)->toContain('可以导出哪些格式？');
});

test('SearchKnowledgeBaseAction 在 rerank 桥失败时 debug.rerank_applied=false 且带 error_code', function (): void {
    seedSearchableDocument(
        $this->kb,
        "# 产品手册\n\n工单流程包含创建、流转、关闭三个阶段。",
    );

    // 配置 rerank 模型，让 SearchKnowledgeBaseAction 进入 rerank 分支。
    $rerankProvider = AiProvider::query()->create([
        'workspace_id' => $this->workspace->id,
        'brand' => 'custom-openai',
        'slug' => 'rerank-'.Str::lower((string) Str::ulid()),
        'name' => 'Rerank Provider',
        'protocol' => 'openai',
        'credential_fields' => [],
        'credentials' => ['key' => 'sk-test'],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $rerankModel = AiModel::query()->create([
        'ai_provider_id' => $rerankProvider->id,
        'model_id' => 'rerank-'.Str::lower((string) Str::ulid()),
        'name' => 'Rerank Model',
        'type' => 'rerank',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->workspace->update(['knowledge_rerank_model_id' => $rerankModel->id]);

    $this->mock(GoKnowledgeBridge::class, function ($mock): void {
        $mock->shouldReceive('rerank')->andThrow(new RuntimeException('rerank upstream down'));
    });

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);
    $result = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Semantic->value,
        'knowledge_base_ids' => [(string) $this->kb->id],
        'query' => '工单',
    ]));

    expect($result->debug['semantic']['rerank_enabled'] ?? null)->toBeTrue()
        ->and($result->debug['semantic']['rerank_applied'] ?? null)->toBeFalse()
        ->and($result->debug['semantic']['rerank_error'] ?? null)->toBe('remote_unavailable');
});

test('SearchKnowledgeBaseAction 在 rerank 桥成功时 debug.rerank_applied=true 且 hits 顺序按 rerank 重排', function (): void {
    seedSearchableDocument(
        $this->kb,
        "# 第一段\n\n工单流程包含创建、流转、关闭三个阶段。\n\n# 第二段\n\n知识库问答覆盖常见问题。\n\n# 第三段\n\n联系客服。",
    );

    $rerankProvider = AiProvider::query()->create([
        'workspace_id' => $this->workspace->id,
        'brand' => 'custom-openai',
        'slug' => 'rerank-ok-'.Str::lower((string) Str::ulid()),
        'name' => 'Rerank Provider',
        'protocol' => 'openai',
        'credential_fields' => [],
        'credentials' => ['key' => 'sk-test'],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $rerankModel = AiModel::query()->create([
        'ai_provider_id' => $rerankProvider->id,
        'model_id' => 'rerank-ok-'.Str::lower((string) Str::ulid()),
        'name' => 'Rerank Model',
        'type' => 'rerank',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $this->workspace->update(['knowledge_rerank_model_id' => $rerankModel->id]);

    // Mock：把最后一条命中分数压高；命中顺序按 rerank 分数重排时，它应该升到第一位。
    $this->mock(GoKnowledgeBridge::class, function ($mock): void {
        $mock->shouldReceive('rerank')->andReturnUsing(static function (
            $_provider,
            $_model,
            $_credentials,
            $_query,
            array $documents,
            int $_topN,
        ): array {
            $results = [];
            $lastIndex = count($documents) - 1;
            $results[] = ['index' => $lastIndex, 'score' => 0.95];
            for ($i = 0; $i < $lastIndex; $i++) {
                $results[] = ['index' => $i, 'score' => 0.1];
            }

            return ['results' => $results];
        });
    });

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);
    $result = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Semantic->value,
        'knowledge_base_ids' => [(string) $this->kb->id],
        'query' => '工单',
    ]));

    expect($result->debug['semantic']['rerank_enabled'] ?? null)->toBeTrue()
        ->and($result->debug['semantic']['rerank_applied'] ?? null)->toBeTrue()
        ->and(array_key_exists('rerank_error', $result->debug['semantic'] ?? []))->toBeFalse();

    // rerank 给"最后一条"打了最高分，排序后它应位于第一条。
    $firstHit = $result->semantic_hits[0] ?? null;
    expect($firstHit)->toBeArray()
        ->and($firstHit['metadata']['rerank_score'] ?? null)->toBe(0.95);
});

test('SearchKnowledgeBaseAction grep 模式可以从 QA 主问题 / 相似问 / 答案三处分别命中', function (): void {
    $qaKb = KnowledgeBase::factory()->create([
        'workspace_id' => $this->workspace->id,
        'category' => KnowledgeBaseCategory::Qa->value,
    ]);
    $entry = seedQaEntry(
        $qaKb,
        '账号被锁定怎么解锁？',
        ['登录提示账号被锁定'],
        ['请联系管理员通过后台 SSO 工单解锁。'],
    );

    /** @var SearchKnowledgeBaseAction $action */
    $action = app(SearchKnowledgeBaseAction::class);

    // 主问题中的字面短语。
    $primary = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Grep->value,
        'knowledge_base_ids' => [(string) $qaKb->id],
        'query' => '账号被锁定怎么解锁',
    ]));
    expect($primary->grep_matches)->not->toBeEmpty();
    $primaryFields = array_unique(array_map(static fn (array $m) => $m['field'] ?? null, $primary->grep_matches));
    expect($primaryFields)->toContain('qa_entry.question');
    expect(collect($primary->grep_matches)->every(fn (array $m) => ($m['qa_entry_id'] ?? null) === (string) $entry->id))->toBeTrue();

    // 相似问中的字面短语。
    $similar = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Grep->value,
        'knowledge_base_ids' => [(string) $qaKb->id],
        'query' => '登录提示账号',
    ]));
    expect($similar->grep_matches)->not->toBeEmpty();
    expect(array_unique(array_map(static fn (array $m) => $m['field'] ?? null, $similar->grep_matches)))
        ->toContain('qa_entry.similar_question');

    // 答案中的字面短语。
    $answer = $action->handle($this->workspace, FormKnowledgeSearchData::from([
        'mode' => KnowledgeSearchMode::Grep->value,
        'knowledge_base_ids' => [(string) $qaKb->id],
        'query' => 'SSO 工单',
    ]));
    expect($answer->grep_matches)->not->toBeEmpty();
    expect(array_unique(array_map(static fn (array $m) => $m['field'] ?? null, $answer->grep_matches)))
        ->toContain('qa_entry.answer');
});
