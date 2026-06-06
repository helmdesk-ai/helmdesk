<?php

use App\Actions\KnowledgeBase\Indexing\IndexKnowledgeQaEntryVectorAction;
use App\Actions\KnowledgeBase\Indexing\WriteCanonicalChunksAction;
use App\Enums\KnowledgeBaseCategory;
use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeIndexingStrategy;
use App\Enums\KnowledgeQaEntryStatus;
use App\Jobs\KnowledgeQa\IndexVectorKnowledgeQaEntryJob;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeQaAnswer;
use App\Models\KnowledgeQaEntry;
use App\Models\KnowledgeQaQuestion;
use App\Models\SystemContext;
use App\Services\KnowledgeBase\GoKnowledgeBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->withoutVite();

    $this->user = $this->createUserWithSystem();
    $this->qaKnowledgeBase = KnowledgeBase::factory()->create([
        'category' => KnowledgeBaseCategory::Qa->value,
        'name' => '产品问答库',
    ]);
});

function createKnowledgeBaseQaTestEmbeddingModel(): AiModel
{
    /** @var SystemContext $systemContext */
    $systemContext = test()->systemContext;

    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'qa-index-'.Str::lower((string) Str::ulid()),
        'name' => 'QA Index Provider',
        'protocol' => 'openai',
        'credential_fields' => [],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    return AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'qa-index-embedding-'.Str::lower((string) Str::ulid()),
        'name' => 'QA Index Embedding Model',
        'type' => 'embedding',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
}

test('超级管理员可以创建带相似问法和多答案的问答条目', function () {
    $defaultGroup = $this->qaKnowledgeBase->defaultDocumentGroup()->firstOrFail();

    $this->actingAs($this->user)
        ->post(
            route('admin.manage.knowledge-bases.qa-entries.store', ['knowledgeBase' => $this->qaKnowledgeBase->id,
            ]),
            [
                'question' => '如何申请退款？',
                'similar_questions' => ['怎么退款？', '退款入口在哪里？'],
                'answers' => ['请在订单详情页提交退款申请。', '企业客户可联系客户成功经理处理。'],
            ]
        )
        ->assertRedirect();

    $entry = KnowledgeQaEntry::query()->firstOrFail();

    expect($entry->knowledge_base_id)->toBe((string) $this->qaKnowledgeBase->id)
        ->and($entry->group_id)->toBe((string) $defaultGroup->id)
        ->and($entry->created_by_user_id)->toBe((string) $this->user->id)
        ->and($entry->question)->toBe('如何申请退款？')
        ->and($entry->status)->toBe(KnowledgeQaEntryStatus::Indexed)
        ->and($entry->vector_status)->toBe(KnowledgeDocumentIndexingStatus::Idle)
        ->and($entry->similarQuestions()->pluck('question')->all())->toBe(['怎么退款？', '退款入口在哪里？'])
        ->and($entry->answers()->pluck('answer')->all())->toBe(['请在订单详情页提交退款申请。', '企业客户可联系客户成功经理处理。']);

    expect($entry->answers()->where('is_default', true)->value('answer'))->toBe('请在订单详情页提交退款申请。');
    expect(DB::connection('sqlite_rag')->table('knowledge_fts')->where('qa_entry_id', $entry->id)->count())->toBe(5);
});

test('标准检索启用时创建问答条目会投递向量索引任务', function () {
    Bus::fake([IndexVectorKnowledgeQaEntryJob::class]);
    $embeddingModel = createKnowledgeBaseQaTestEmbeddingModel();
    $this->systemContext->update([
        'knowledge_embedding_model_id' => $embeddingModel->id,
        'knowledge_vector_index_enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->post(
            route('admin.manage.knowledge-bases.qa-entries.store', ['knowledgeBase' => $this->qaKnowledgeBase->id,
            ]),
            [
                'question' => '如何申请退款？',
                'similar_questions' => ['怎么退款？'],
                'answers' => ['请在订单详情页提交退款申请。'],
            ]
        )
        ->assertRedirect();

    $entry = KnowledgeQaEntry::query()->firstOrFail();

    expect($entry->status)->toBe(KnowledgeQaEntryStatus::Pending)
        ->and($entry->vector_status)->toBe(KnowledgeDocumentIndexingStatus::Pending);

    Bus::assertDispatched(
        IndexVectorKnowledgeQaEntryJob::class,
        static fn (IndexVectorKnowledgeQaEntryJob $job): bool => $job->entryId === (string) $entry->id,
    );
});

test('问答向量索引写入主问题和相似问法节点', function () {
    $embeddingModel = createKnowledgeBaseQaTestEmbeddingModel();
    $this->systemContext->update([
        'knowledge_embedding_model_id' => $embeddingModel->id,
        'knowledge_vector_index_enabled' => true,
    ]);
    app()->bind(GoKnowledgeBridge::class, fn () => new class extends GoKnowledgeBridge
    {
        public function __construct() {}

        public function embedTexts(AiProvider $_provider, AiModel $_model, array $_credentials, array $contents): array
        {
            return [
                'dimension' => 3,
                'embeddings' => array_map(static fn () => [0.1, 0.2, 0.3], $contents),
            ];
        }
    });

    /** @var KnowledgeQaEntry $entry */
    $entry = KnowledgeQaEntry::factory()->create([
        'knowledge_base_id' => $this->qaKnowledgeBase->id,
        'question' => '如何申请退款？',
        'vector_status' => KnowledgeDocumentIndexingStatus::Pending,
        'status' => KnowledgeQaEntryStatus::Pending,
    ]);
    KnowledgeQaQuestion::factory()->create([
        'knowledge_qa_entry_id' => $entry->id,
        'question' => '退款入口在哪里？',
        'sort_order' => 0,
    ]);
    KnowledgeQaQuestion::factory()->create([
        'knowledge_qa_entry_id' => $entry->id,
        'question' => '怎么退款？',
        'sort_order' => 1,
    ]);

    // Vector 索引强依赖 canonical 节点，先把 canonical/FTS 写入做出来。
    app(WriteCanonicalChunksAction::class)->forQaEntry($entry);

    app(IndexKnowledgeQaEntryVectorAction::class)->handle($entry);

    // 新设计下，向量直接挂在 strategy=Text 的 canonical 节点上（attachVectors 只更新
    // embedding_dim/embedding_model_id，不再单独创建 strategy=Vector 节点）。
    // 仅给问题角色（qa_primary / qa_similar）打向量，答案节点保持 embedding_dim=null。
    $entry->refresh();
    $textNodes = KnowledgeNode::query()
        ->where('qa_entry_id', $entry->id)
        ->where('strategy', KnowledgeIndexingStrategy::Text->value)
        ->get();
    $questionNodes = $textNodes
        ->filter(static fn (KnowledgeNode $node): bool => in_array($node->metadata['qa_role'] ?? null, ['qa_primary', 'qa_similar'], true))
        ->values();
    $answerNodes = $textNodes
        ->filter(static fn (KnowledgeNode $node): bool => ($node->metadata['qa_role'] ?? null) === 'qa_answer')
        ->values();

    expect($entry->status)->toBe(KnowledgeQaEntryStatus::Indexed)
        ->and($entry->vector_status)->toBe(KnowledgeDocumentIndexingStatus::Succeeded)
        ->and($questionNodes)->toHaveCount(3)
        ->and($questionNodes->pluck('content')->sort()->values()->all())
        ->toBe(collect(['如何申请退款？', '退款入口在哪里？', '怎么退款？'])->sort()->values()->all())
        ->and($questionNodes->every(fn (KnowledgeNode $n) => (int) $n->embedding_dim === 3))->toBeTrue()
        ->and($answerNodes->every(fn (KnowledgeNode $n) => $n->embedding_dim === null))->toBeTrue();
});

test('问答条目只能添加到问答知识库', function () {
    $standardKnowledgeBase = KnowledgeBase::factory()->create([
        'category' => KnowledgeBaseCategory::Standard->value,
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->withHeader('X-Inertia', 'true')
        ->post(
            route('admin.manage.knowledge-bases.qa-entries.store', ['knowledgeBase' => $standardKnowledgeBase->id,
            ]),
            [
                'question' => '如何申请退款？',
                'answers' => ['请在订单详情页提交退款申请。'],
            ]
        )
        ->assertSessionHasErrors('toast');

    expect(KnowledgeQaEntry::query()->where('knowledge_base_id', $standardKnowledgeBase->id)->count())->toBe(0);
});

test('编辑问答条目会替换相似问法和答案', function () {
    /** @var KnowledgeQaEntry $entry */
    $entry = KnowledgeQaEntry::factory()->create([
        'knowledge_base_id' => $this->qaKnowledgeBase->id,
        'question' => '旧问题',
    ]);
    KnowledgeQaQuestion::factory()->create([
        'knowledge_qa_entry_id' => $entry->id,
        'question' => '旧相似问法',
    ]);
    KnowledgeQaAnswer::factory()->create([
        'knowledge_qa_entry_id' => $entry->id,
        'answer' => '旧答案',
    ]);

    $this->actingAs($this->user)
        ->put(
            route('admin.manage.knowledge-bases.qa-entries.update', ['knowledgeBase' => $this->qaKnowledgeBase->id,
                'entry' => $entry->id,
            ]),
            [
                'question' => '新问题',
                'similar_questions' => ['新相似问法 A', '新相似问法 B'],
                'answers' => ['新答案 A', '新答案 B'],
            ]
        )
        ->assertRedirect();

    $entry->refresh();

    expect($entry->question)->toBe('新问题')
        ->and($entry->similarQuestions()->pluck('question')->all())->toBe(['新相似问法 A', '新相似问法 B'])
        ->and($entry->answers()->pluck('answer')->all())->toBe(['新答案 A', '新答案 B'])
        ->and($entry->answers()->where('is_default', true)->value('answer'))->toBe('新答案 A');
});

test('问答条目可以移动分组', function () {
    $group = $this->qaKnowledgeBase->documentGroups()->create([
        'name' => '售后',
        'sort_order' => 1,
    ]);
    /** @var KnowledgeQaEntry $entry */
    $entry = KnowledgeQaEntry::factory()->create([
        'knowledge_base_id' => $this->qaKnowledgeBase->id,
    ]);

    $this->actingAs($this->user)
        ->put(
            route('admin.manage.knowledge-bases.qa-entries.move', ['knowledgeBase' => $this->qaKnowledgeBase->id,
                'entry' => $entry->id,
            ]),
            ['group_id' => $group->id]
        )
        ->assertRedirect();

    expect($entry->fresh()->group_id)->toBe((string) $group->id);
});

test('包含问答条目的分组不能删除', function () {
    $group = $this->qaKnowledgeBase->documentGroups()->create([
        'name' => '售后',
        'sort_order' => 1,
    ]);
    KnowledgeQaEntry::factory()->create([
        'knowledge_base_id' => $this->qaKnowledgeBase->id,
        'group_id' => $group->id,
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.knowledge-bases.index'))
        ->delete(
            route('admin.manage.knowledge-bases.groups.destroy', ['knowledgeBase' => $this->qaKnowledgeBase->id,
                'group' => $group->id,
            ])
        )
        ->assertSessionHasErrors(['group']);

    expect($group->fresh())->not->toBeNull();
});

test('问答知识库列表返回问答条目并可按相似问法和答案搜索', function () {
    /** @var KnowledgeQaEntry $matchedBySimilarQuestion */
    $matchedBySimilarQuestion = KnowledgeQaEntry::factory()->create([
        'knowledge_base_id' => $this->qaKnowledgeBase->id,
        'question' => '如何申请退款？',
    ]);
    KnowledgeQaQuestion::factory()->create([
        'knowledge_qa_entry_id' => $matchedBySimilarQuestion->id,
        'question' => '退款入口在哪里？',
    ]);
    KnowledgeQaAnswer::factory()->create([
        'knowledge_qa_entry_id' => $matchedBySimilarQuestion->id,
        'answer' => '请在订单详情页提交申请。',
    ]);

    /** @var KnowledgeQaEntry $matchedByAnswer */
    $matchedByAnswer = KnowledgeQaEntry::factory()->create([
        'knowledge_base_id' => $this->qaKnowledgeBase->id,
        'question' => '如何修改手机号？',
    ]);
    KnowledgeQaAnswer::factory()->create([
        'knowledge_qa_entry_id' => $matchedByAnswer->id,
        'answer' => '退款申请需要在订单详情页提交。',
    ]);

    KnowledgeQaEntry::factory()->create([
        'knowledge_base_id' => $this->qaKnowledgeBase->id,
        'question' => '如何开票？',
    ]);

    $this->actingAs($this->user)
        ->get(
            route('admin.manage.knowledge-bases.index', ['kb' => $this->qaKnowledgeBase->id,
                'search' => '退款',
            ])
        )
        ->assertInertia(fn ($page) => $page
            ->component('knowledgeBase/List')
            ->where('search', '退款')
            ->has('document_list', 0)
            ->has('qa_entry_list', 2)
        );
});

test('删除问答条目会同步删除相似问法和答案', function () {
    /** @var KnowledgeQaEntry $entry */
    $entry = KnowledgeQaEntry::factory()->create([
        'knowledge_base_id' => $this->qaKnowledgeBase->id,
    ]);
    KnowledgeQaQuestion::factory()->create(['knowledge_qa_entry_id' => $entry->id]);
    KnowledgeQaAnswer::factory()->create(['knowledge_qa_entry_id' => $entry->id]);

    $this->actingAs($this->user)
        ->delete(
            route('admin.manage.knowledge-bases.qa-entries.destroy', ['knowledgeBase' => $this->qaKnowledgeBase->id,
                'entry' => $entry->id,
            ])
        )
        ->assertRedirect();

    expect(KnowledgeQaEntry::query()->whereKey($entry->id)->exists())->toBeFalse()
        ->and(KnowledgeQaQuestion::query()->where('knowledge_qa_entry_id', $entry->id)->exists())->toBeFalse()
        ->and(KnowledgeQaAnswer::query()->where('knowledge_qa_entry_id', $entry->id)->exists())->toBeFalse();
});
