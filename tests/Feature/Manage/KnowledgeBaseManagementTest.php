<?php

use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeIndexingStrategy;
use App\Enums\KnowledgeNodeKind;
use App\Jobs\KnowledgeDocument\IndexRaptorKnowledgeDocumentJob;
use App\Jobs\KnowledgeDocument\IndexVectorKnowledgeDocumentJob;
use App\Jobs\KnowledgeQa\IndexVectorKnowledgeQaEntryJob;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Attachment;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeGroup;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeQaEntry;
use App\Models\SystemContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();
});

function createKnowledgeBaseTestAttachment(array $attributes = []): Attachment
{
    /** @var SystemContext $systemContext */
    $systemContext = test()->systemContext;

    return Attachment::factory()->create(array_merge([
        'disk' => 'local',
        'object_key' => 'systems/'.$systemContext->id.'/knowledge_base_avatar/'.Str::lower(Str::random(8)).'.png',
        'original_name' => 'knowledge-base.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 1024,
        'visibility' => 'public',
        'purpose' => 'avatar',
        'status' => 'uploaded',
    ], $attributes));
}

function createKnowledgeBaseTestAiModel(string $type = 'embedding', ?AiProvider $provider = null): AiModel
{
    /** @var SystemContext $systemContext */
    $systemContext = test()->systemContext;

    $provider ??= AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'kb-test-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Test Provider',
        'protocol' => 'openai',
        'credential_fields' => [],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);

    return AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'kb-test-'.$type.'-'.Str::lower((string) Str::ulid()),
        'name' => 'KB Test '.Str::title($type).' Model',
        'type' => $type,
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
}

test('所有者可以查看知识库列表页面和系统检索配置', function () {
    $embeddingModel = createKnowledgeBaseTestAiModel('embedding');
    $summaryModel = createKnowledgeBaseTestAiModel('llm', $embeddingModel->provider);
    $this->systemContext->update([
        'knowledge_embedding_model_id' => $embeddingModel->id,
        'knowledge_summary_model_id' => $summaryModel->id,
        'knowledge_vector_index_enabled' => true,
        'knowledge_raptor_index_enabled' => true,
        'knowledge_chunking_strategy' => KnowledgeChunkingStrategy::Semantic->value,
        'knowledge_chunk_max_tokens' => 768,
        'knowledge_chunk_overlap_tokens' => 96,
    ]);
    KnowledgeBase::factory()->create([
        'name' => '售后政策库',
        'description' => '退款、退货和换货规则',
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.knowledge-bases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledgeBase/List')
            ->has('knowledge_base_list', 1)
            ->where('knowledge_base_list.0.name', '售后政策库')
            ->where('knowledge_base_list.0.avatar_id', null)
            ->where('knowledge_base_list.0.avatar_url', null)
            ->where('system_knowledge_settings.embedding_model_id', (string) $embeddingModel->id)
            ->where('system_knowledge_settings.summary_model_id', (string) $summaryModel->id)
            ->where('system_knowledge_settings.vector_index_enabled', true)
            ->where('system_knowledge_settings.raptor_index_enabled', true)
            ->where('system_knowledge_settings.chunking_strategy', KnowledgeChunkingStrategy::Semantic->value)
            ->where('selected_knowledge_base', null)
        );
});

test('知识库索引展示文案使用标准索引和深度索引', function () {
    $this->user->forceFill(['locale' => 'zh-CN'])->save();
    app()->setLocale('zh_CN');

    $this->systemContext->forceFill([
        'knowledge_vector_index_enabled' => true,
        'knowledge_raptor_index_enabled' => true,
    ])->save();

    /** @var KnowledgeBase $knowledgeBase */
    $knowledgeBase = KnowledgeBase::factory()->create([
        'name' => '索引文案知识库',
    ]);
    /** @var KnowledgeGroup $defaultGroup */
    $defaultGroup = $knowledgeBase->defaultDocumentGroup()->firstOrFail();
    KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $knowledgeBase->id,
        'group_id' => $defaultGroup->id,
        'original_filename' => 'index-labels.md',
    ]);

    expect(KnowledgeIndexingStrategy::Vector->label())->toBe('标准索引')
        ->and(KnowledgeIndexingStrategy::Raptor->label())->toBe('深度索引');

    $this->actingAs($this->user)
        ->get(route('admin.manage.knowledge-bases.index', ['kb' => $knowledgeBase->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledgeBase/List')
            ->where('indexing_strategy_options.0.label', '标准索引')
            ->where('indexing_strategy_options.1.label', '深度索引')
            ->where('document_list.0.indexing.stages.1.stage_label', '标准索引')
            ->where('document_list.0.indexing.stages.2.stage_label', '深度索引')
        );
});

test('知识库列表按当前语言展示默认分组', function () {
    $this->user->forceFill(['locale' => 'en'])->save();

    KnowledgeBase::factory()->create([
        'name' => '售后政策库',
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.knowledge-bases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledgeBase/List')
            ->where('knowledge_base_list.0.document_groups.0.name', 'Default Group')
            ->where('knowledge_base_list.0.document_groups.0.is_default', true)
        );
});

test('知识库列表按创建时间从旧到新排列', function () {
    KnowledgeBase::factory()->create([
        'name' => '旧知识库',
        'created_at' => now()->subMinutes(2),
    ]);
    KnowledgeBase::factory()->create([
        'name' => '中间知识库',
        'created_at' => now()->subMinute(),
    ]);
    KnowledgeBase::factory()->create([
        'name' => '新知识库',
        'created_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.knowledge-bases.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledgeBase/List')
            ->where('knowledge_base_list.0.name', '旧知识库')
            ->where('knowledge_base_list.1.name', '中间知识库')
            ->where('knowledge_base_list.2.name', '新知识库')
        );
});

test('所有者可以打开创建和编辑知识库页面', function () {
    $knowledgeBase = KnowledgeBase::factory()->create([
        'name' => '产品知识库',
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.knowledge-bases.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledgeBase/Create')
        );

    $this->actingAs($this->user)
        ->get(route('admin.manage.knowledge-bases.edit', ['knowledgeBase' => $knowledgeBase->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledgeBase/Edit')
            ->where('knowledge_base_form.id', (string) $knowledgeBase->id)
            ->where('knowledge_base_form.name', '产品知识库')
        );
});

test('所有者可以创建更新并删除知识库', function () {
    Storage::fake('local');
    $avatar = createKnowledgeBaseTestAttachment();
    $updatedAvatar = createKnowledgeBaseTestAttachment();

    $response = $this->actingAs($this->user)
        ->post(route('admin.manage.knowledge-bases.store'), [
            'name' => '帮助中心知识库',
            'avatar_id' => $avatar->id,
            'description' => '常见问题和操作说明',
            'category' => 'standard',
        ]);

    $knowledgeBase = KnowledgeBase::query()
        ->firstOrFail();

    $response->assertRedirect(route('admin.manage.knowledge-bases.index', ['kb' => $knowledgeBase->id,
    ]));

    expect($knowledgeBase->name)->toBe('帮助中心知识库')
        ->and($knowledgeBase->description)->toBe('常见问题和操作说明')
        ->and($knowledgeBase->avatar_id)->toBe($avatar->id)
        ->and($avatar->fresh()->attachable_id)->toBe($knowledgeBase->id);

    $defaultGroup = KnowledgeGroup::query()
        ->where('knowledge_base_id', $knowledgeBase->id)
        ->where('is_default', true)
        ->firstOrFail();

    expect($defaultGroup->name)->toBe(KnowledgeBase::DEFAULT_GROUP_NAME)
        ->and($defaultGroup->parent_id)->toBeNull();

    $this->actingAs($this->user)
        ->put(route('admin.manage.knowledge-bases.update', ['knowledgeBase' => $knowledgeBase->id,
        ]), [
            'name' => '帮助中心知识库 Plus',
            'avatar_id' => $updatedAvatar->id,
            'description' => '更新后的常见问题',
            'category' => 'standard',
        ])
        ->assertRedirect();

    $knowledgeBase->refresh();
    expect($knowledgeBase->name)->toBe('帮助中心知识库 Plus')
        ->and($knowledgeBase->description)->toBe('更新后的常见问题')
        ->and($knowledgeBase->avatar_id)->toBe($updatedAvatar->id)
        ->and($updatedAvatar->fresh()->attachable_id)->toBe($knowledgeBase->id);

    $this->actingAs($this->user)
        ->delete(route('admin.manage.knowledge-bases.destroy', ['knowledgeBase' => $knowledgeBase->id,
        ]))
        ->assertRedirect(route('admin.manage.knowledge-bases.index'));

    $this->assertDatabaseMissing('knowledge_bases', [
        'id' => $knowledgeBase->id,
    ]);
    $this->assertDatabaseMissing('knowledge_groups', [
        'id' => $defaultGroup->id,
    ]);
});

test('删除知识库会一并清空 sqlite_rag 中的节点 / 全文 / 大纲', function () {
    /** @var KnowledgeBase $knowledgeBase */
    $knowledgeBase = KnowledgeBase::factory()->create([
        'name' => '将被删除的知识库',
    ]);
    /** @var KnowledgeDocument $document */
    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $knowledgeBase->id,
    ]);

    KnowledgeNode::query()->create([
        'knowledge_base_id' => (string) $knowledgeBase->id,
        'document_id' => (string) $document->id,
        'strategy' => KnowledgeIndexingStrategy::Vector,
        'level' => 0,
        'kind' => KnowledgeNodeKind::Segment,
        'content' => '残留节点',
        'content_format' => 'markdown',
        'embedding_dim' => 0,
    ]);
    DB::connection('sqlite_rag')->table('knowledge_fts')->insert([
        'search_content' => '残留全文',
        'content' => '残留全文',
        'heading_path' => null,
        'document_id' => (string) $document->id,
        'knowledge_base_id' => (string) $knowledgeBase->id,
        'group_id' => (string) $document->group_id,
        'node_id' => (string) Str::ulid(),
    ]);
    DB::connection('sqlite_rag')->table('knowledge_outlines')->insert([
        'id' => (string) Str::ulid(),
        'document_id' => (string) $document->id,
        'knowledge_base_id' => (string) $knowledgeBase->id,
        'outline' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->delete(route('admin.manage.knowledge-bases.destroy', ['knowledgeBase' => $knowledgeBase->id,
        ]))
        ->assertRedirect();

    expect(KnowledgeNode::query()->where('knowledge_base_id', (string) $knowledgeBase->id)->exists())->toBeFalse()
        ->and(DB::connection('sqlite_rag')
            ->table('knowledge_fts')->where('knowledge_base_id', (string) $knowledgeBase->id)->count())->toBe(0)
        ->and(DB::connection('sqlite_rag')
            ->table('knowledge_outlines')->where('knowledge_base_id', (string) $knowledgeBase->id)->exists())->toBeFalse();
});

test('所有者可以保存系统知识库检索配置', function () {
    $embeddingModel = createKnowledgeBaseTestAiModel('embedding');
    $rerankModel = createKnowledgeBaseTestAiModel('rerank', $embeddingModel->provider);
    $summaryModel = createKnowledgeBaseTestAiModel('llm', $embeddingModel->provider);

    $this->actingAs($this->user)
        ->put(route('admin.manage.knowledge-bases.settings.update'), [
            'embedding_model_id' => $embeddingModel->id,
            'embedding_dimension' => 1536,
            'rerank_model_id' => $rerankModel->id,
            'summary_model_id' => $summaryModel->id,
            'vector_index_enabled' => true,
            'raptor_index_enabled' => true,
            'chunking_strategy' => KnowledgeChunkingStrategy::Semantic->value,
            'chunk_max_tokens' => 768,
            'chunk_overlap_tokens' => 96,
        ])
        ->assertRedirect();

    $this->systemContext->refresh();
    expect($this->systemContext->knowledge_embedding_model_id)->toBe($embeddingModel->id)
        ->and($this->systemContext->knowledge_embedding_dimension)->toBe(1536)
        ->and($this->systemContext->knowledge_rerank_model_id)->toBe($rerankModel->id)
        ->and($this->systemContext->knowledge_summary_model_id)->toBe($summaryModel->id)
        ->and($this->systemContext->knowledge_vector_index_enabled)->toBeTrue()
        ->and($this->systemContext->knowledge_raptor_index_enabled)->toBeTrue()
        ->and($this->systemContext->knowledge_chunking_strategy)->toBe(KnowledgeChunkingStrategy::Semantic)
        ->and($this->systemContext->knowledge_chunk_max_tokens)->toBe(768)
        ->and($this->systemContext->knowledge_chunk_overlap_tokens)->toBe(96);
});

test('启用向量索引但未填写维度时返回字段级校验错误', function () {
    $embeddingModel = createKnowledgeBaseTestAiModel('embedding');

    $this->actingAs($this->user)
        ->put(route('admin.manage.knowledge-bases.settings.update'), [
            'embedding_model_id' => $embeddingModel->id,
            'embedding_dimension' => '',
            'vector_index_enabled' => true,
            'raptor_index_enabled' => false,
            'chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
            'chunk_max_tokens' => 512,
            'chunk_overlap_tokens' => 64,
        ])
        ->assertSessionHasErrors(['embedding_dimension']);
});

test('标准索引启用时需要嵌入模型', function () {
    $this->actingAs($this->user)
        ->put(route('admin.manage.knowledge-bases.settings.update'), [
            'embedding_model_id' => '',
            'vector_index_enabled' => true,
            'raptor_index_enabled' => false,
            'chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
            'chunk_max_tokens' => 512,
            'chunk_overlap_tokens' => 64,
        ])
        ->assertSessionHasErrors(['embedding_model_id']);
});

test('深度索引启用时同样需要嵌入模型（摘要节点也要落向量）', function () {
    $summaryModel = createKnowledgeBaseTestAiModel('llm');

    $this->actingAs($this->user)
        ->put(route('admin.manage.knowledge-bases.settings.update'), [
            'embedding_model_id' => '',
            'summary_model_id' => $summaryModel->id,
            'vector_index_enabled' => false,
            'raptor_index_enabled' => true,
            'chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
            'chunk_max_tokens' => 512,
            'chunk_overlap_tokens' => 64,
        ])
        ->assertSessionHasErrors(['embedding_model_id']);
});

test('深度索引启用时需要摘要模型', function () {
    $this->actingAs($this->user)
        ->put(route('admin.manage.knowledge-bases.settings.update'), [
            'summary_model_id' => '',
            'vector_index_enabled' => false,
            'raptor_index_enabled' => true,
            'chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
            'chunk_max_tokens' => 512,
            'chunk_overlap_tokens' => 64,
        ])
        ->assertSessionHasErrors(['summary_model_id']);
});

test('更新系统检索配置会清理索引并投递已解析文档', function () {
    Bus::fake([
        IndexVectorKnowledgeDocumentJob::class,
        IndexRaptorKnowledgeDocumentJob::class,
        IndexVectorKnowledgeQaEntryJob::class,
    ]);

    $embeddingModel = createKnowledgeBaseTestAiModel('embedding');
    $updatedEmbeddingModel = createKnowledgeBaseTestAiModel('embedding', $embeddingModel->provider);
    $summaryModel = createKnowledgeBaseTestAiModel('llm', $embeddingModel->provider);
    $updatedSummaryModel = createKnowledgeBaseTestAiModel('llm', $embeddingModel->provider);
    $this->systemContext->update([
        'knowledge_embedding_model_id' => $embeddingModel->id,
        'knowledge_embedding_dimension' => 1024,
        'knowledge_summary_model_id' => $summaryModel->id,
        'knowledge_vector_index_enabled' => true,
        'knowledge_raptor_index_enabled' => true,
        'knowledge_chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
        'knowledge_chunk_max_tokens' => 512,
        'knowledge_chunk_overlap_tokens' => 64,
    ]);
    $knowledgeBase = KnowledgeBase::factory()->create([
        'name' => '帮助中心知识库',
    ]);
    $qaKnowledgeBase = KnowledgeBase::factory()->create([
        'category' => 'qa',
        'name' => '帮助中心问答库',
    ]);
    /** @var KnowledgeQaEntry $qaEntry */
    $qaEntry = KnowledgeQaEntry::factory()->create([
        'knowledge_base_id' => $qaKnowledgeBase->id,
        'vector_status' => KnowledgeDocumentIndexingStatus::Succeeded,
    ]);

    /** @var KnowledgeDocument $parsedDocument */
    $parsedDocument = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $knowledgeBase->id,
        'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
        'parsed_content' => "# 已解析文档\n\n正文",
        'vector_status' => KnowledgeDocumentIndexingStatus::Succeeded,
        'raptor_status' => KnowledgeDocumentIndexingStatus::Succeeded,
        'status' => KnowledgeDocumentStatus::Indexed,
    ]);

    /** @var KnowledgeDocument $pendingDocument */
    $pendingDocument = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $knowledgeBase->id,
        'parse_status' => KnowledgeDocumentParseStatus::Pending,
        'vector_status' => KnowledgeDocumentIndexingStatus::Succeeded,
        'raptor_status' => KnowledgeDocumentIndexingStatus::Succeeded,
        'status' => KnowledgeDocumentStatus::Indexed,
    ]);

    foreach ([$parsedDocument, $pendingDocument] as $document) {
        foreach ([KnowledgeIndexingStrategy::Vector, KnowledgeIndexingStrategy::Raptor] as $strategy) {
            KnowledgeNode::query()->create([
                'knowledge_base_id' => (string) $knowledgeBase->id,
                'document_id' => (string) $document->id,
                'strategy' => $strategy,
                'level' => 0,
                'kind' => KnowledgeNodeKind::Segment,
                'content' => '已有索引节点',
                'content_format' => 'markdown',
                'embedding_model_id' => (string) $embeddingModel->id,
                'embedding_dim' => 0,
            ]);
        }
    }
    KnowledgeNode::query()->create([
        'knowledge_base_id' => (string) $qaKnowledgeBase->id,
        'document_id' => null,
        'qa_entry_id' => (string) $qaEntry->id,
        'strategy' => KnowledgeIndexingStrategy::Vector,
        'level' => 0,
        'kind' => KnowledgeNodeKind::Segment,
        'content' => '已有问答索引节点',
        'content_format' => 'text',
        'embedding_model_id' => (string) $embeddingModel->id,
        'embedding_dim' => 0,
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.knowledge-bases.settings.update'), [
            'embedding_model_id' => $updatedEmbeddingModel->id,
            'embedding_dimension' => 1536,
            'summary_model_id' => $updatedSummaryModel->id,
            'vector_index_enabled' => true,
            'raptor_index_enabled' => true,
            'chunking_strategy' => KnowledgeChunkingStrategy::Semantic->value,
            'chunk_max_tokens' => 640,
            'chunk_overlap_tokens' => 80,
        ])
        ->assertRedirect();

    expect(KnowledgeNode::query()
        ->where('knowledge_base_id', $knowledgeBase->id)
        ->whereIn('strategy', [KnowledgeIndexingStrategy::Vector->value, KnowledgeIndexingStrategy::Raptor->value])
        ->exists())->toBeFalse()
        ->and(KnowledgeNode::query()
            ->where('qa_entry_id', $qaEntry->id)
            ->where('strategy', KnowledgeIndexingStrategy::Vector->value)
            ->exists())->toBeFalse()
        ->and($parsedDocument->fresh()->vector_status)->toBe(KnowledgeDocumentIndexingStatus::Pending)
        ->and($parsedDocument->fresh()->raptor_status)->toBe(KnowledgeDocumentIndexingStatus::Pending)
        ->and($pendingDocument->fresh()->vector_status)->toBe(KnowledgeDocumentIndexingStatus::Pending)
        ->and($pendingDocument->fresh()->raptor_status)->toBe(KnowledgeDocumentIndexingStatus::Pending)
        ->and($qaEntry->fresh()->vector_status)->toBe(KnowledgeDocumentIndexingStatus::Pending);

    expect((int) $this->systemContext->fresh()->knowledge_embedding_dimension)->toBe(1536);

    Bus::assertDispatchedTimes(IndexVectorKnowledgeDocumentJob::class, 1);
    Bus::assertDispatchedTimes(IndexRaptorKnowledgeDocumentJob::class, 1);
    Bus::assertDispatchedTimes(IndexVectorKnowledgeQaEntryJob::class, 1);
    Bus::assertDispatched(
        IndexVectorKnowledgeDocumentJob::class,
        static fn (IndexVectorKnowledgeDocumentJob $job): bool => $job->documentId === (string) $parsedDocument->id,
    );
    Bus::assertDispatched(
        IndexRaptorKnowledgeDocumentJob::class,
        static fn (IndexRaptorKnowledgeDocumentJob $job): bool => $job->documentId === (string) $parsedDocument->id,
    );
    Bus::assertDispatched(
        IndexVectorKnowledgeQaEntryJob::class,
        static fn (IndexVectorKnowledgeQaEntryJob $job): bool => $job->entryId === (string) $qaEntry->id,
    );
});

test('维度变化时清空 vec0 注册表并把已有 Text 节点的 embedding_dim 重置', function () {
    Bus::fake([
        IndexVectorKnowledgeDocumentJob::class,
        IndexRaptorKnowledgeDocumentJob::class,
        IndexVectorKnowledgeQaEntryJob::class,
    ]);

    $embeddingModel = createKnowledgeBaseTestAiModel('embedding');
    $summaryModel = createKnowledgeBaseTestAiModel('llm', $embeddingModel->provider);
    $this->systemContext->update([
        'knowledge_embedding_model_id' => $embeddingModel->id,
        'knowledge_embedding_dimension' => 1024,
        'knowledge_summary_model_id' => $summaryModel->id,
        'knowledge_vector_index_enabled' => true,
        'knowledge_raptor_index_enabled' => true,
    ]);
    $knowledgeBase = KnowledgeBase::factory()->create([
        'name' => '维度变更知识库',
    ]);
    $document = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $knowledgeBase->id,
        'parse_status' => KnowledgeDocumentParseStatus::Succeeded,
        'parsed_content' => "# 标题\n\n正文段落",
    ]);
    $canonicalNode = KnowledgeNode::query()->create([
        'knowledge_base_id' => (string) $knowledgeBase->id,
        'document_id' => (string) $document->id,
        'strategy' => KnowledgeIndexingStrategy::Text,
        'level' => 0,
        'kind' => KnowledgeNodeKind::Segment,
        'content' => '已嵌入文本',
        'content_format' => 'markdown',
        'embedding_model_id' => (string) $embeddingModel->id,
        'embedding_dim' => 1024,
    ]);

    DB::connection('sqlite_rag')->statement(
        'CREATE VIRTUAL TABLE IF NOT EXISTS knowledge_node_vectors_1024 USING vec0(node_id TEXT PRIMARY KEY, embedding FLOAT[1024])',
    );
    DB::connection('sqlite_rag')->table('knowledge_vector_tables')->insertOrIgnore([
        'dimension' => 1024,
        'table_name' => 'knowledge_node_vectors_1024',
        'created_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.knowledge-bases.settings.update'), [
            'embedding_model_id' => $embeddingModel->id,
            'embedding_dimension' => 1536,
            'summary_model_id' => $summaryModel->id,
            'vector_index_enabled' => true,
            'raptor_index_enabled' => true,
            'chunking_strategy' => KnowledgeChunkingStrategy::Fixed->value,
            'chunk_max_tokens' => 512,
            'chunk_overlap_tokens' => 64,
        ])
        ->assertRedirect();

    expect((int) $this->systemContext->fresh()->knowledge_embedding_dimension)->toBe(1536)
        ->and(DB::connection('sqlite_rag')->table('knowledge_vector_tables')->count())->toBe(0)
        ->and((int) $canonicalNode->fresh()->embedding_dim)->toBe(0)
        ->and($canonicalNode->fresh()->embedding_model_id)->toBeNull();
});

test('知识库名称必须唯一', function () {
    KnowledgeBase::factory()->create([
        'name' => '重复名称',
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.manage.knowledge-bases.store'), [
            'name' => '重复名称',
            'description' => '',
            'category' => 'standard',
        ])
        ->assertSessionHasErrors(['name']);
});

test('知识库头像需要来自可用附件', function () {
    $otherKnowledgeBase = KnowledgeBase::factory()->create([
    ]);
    $foreignAttachment = createKnowledgeBaseTestAttachment([
        'attachable_type' => KnowledgeBase::class,
        'attachable_id' => $otherKnowledgeBase->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('admin.manage.knowledge-bases.store'), [
            'name' => '尝试占用头像',
            'avatar_id' => $foreignAttachment->id,
            'description' => '',
            'category' => 'standard',
        ])
        ->assertUnprocessable()
        ->assertJson([
            'message' => __('knowledge_base.messages.invalid_attachment'),
        ]);

    expect($foreignAttachment->fresh()->attachable_id)->toBe($otherKnowledgeBase->id);
});

test('非超级管理员没有知识库管理权限', function () {
    $admin = User::factory()->create();

    $operator = User::factory()->create();

    $knowledgeBase = KnowledgeBase::factory()->create([
    ]);

    $this->actingAs($admin)
        ->get(route('admin.manage.knowledge-bases.index'))
        ->assertForbidden();

    $this->actingAs($operator)
        ->get(route('admin.manage.knowledge-bases.index'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->put(route('admin.manage.knowledge-bases.update', ['knowledgeBase' => $knowledgeBase->id,
        ]), [
            'name' => '非法更新',
            'description' => '',
            'category' => 'standard',
        ])
        ->assertForbidden();
});

test('单租户后台可以操作任意知识库', function () {
    $knowledgeBase = KnowledgeBase::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.knowledge-bases.edit', ['knowledgeBase' => $knowledgeBase->id,
        ]))
        ->assertOk();

    $this->actingAs($this->user)
        ->delete(route('admin.manage.knowledge-bases.destroy', ['knowledgeBase' => $knowledgeBase->id,
        ]))
        ->assertRedirect();

    expect(KnowledgeBase::query()->whereKey($knowledgeBase->id)->exists())->toBeFalse();
});
