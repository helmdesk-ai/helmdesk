<?php

namespace App\Models;

use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\KnowledgeIndexingStrategy;
use App\Settings\GeneralSettings;
use App\Settings\KnowledgeSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $name
 * @property string|null $slug
 * @property string|null $logo_id
 * @property string|null $owner_id
 * @property string|null $knowledge_embedding_model_id
 * @property string|null $knowledge_rerank_model_id
 * @property string|null $knowledge_summary_model_id
 * @property int|null $knowledge_embedding_dimension
 * @property bool $knowledge_vector_index_enabled
 * @property bool $knowledge_raptor_index_enabled
 * @property KnowledgeChunkingStrategy $knowledge_chunking_strategy
 * @property int $knowledge_chunk_max_tokens
 * @property int $knowledge_chunk_overlap_tokens
 * @property Carbon|null $deleted_at
 * @property mixed $use_factory
 * @property mixed $logoUrl
 * @property mixed $logo_url
 * @property int|null $knowledge_embedding_models_count
 * @property int|null $knowledge_rerank_models_count
 * @property int|null $knowledge_summary_models_count
 * @property int|null $tags_count
 * @property int|null $ai_providers_count
 * @property int|null $mcp_servers_count
 * @property int|null $translation_providers_count
 * @property int|null $knowledge_bases_count
 * @property int|null $channels_count
 * @property int|null $contacts_count
 * @property int|null $conversations_count
 * @property int|null $attribute_definitions_count
 * @property-read AiModel|null $knowledgeEmbeddingModel
 * @property-read AiModel|null $knowledgeRerankModel
 * @property-read AiModel|null $knowledgeSummaryModel
 * @property-read Collection|Tag[] $tags
 * @property-read Collection|AiProvider[] $aiProviders
 * @property-read Collection|McpServer[] $mcpServers
 * @property-read Collection|TranslationProvider[] $translationProviders
 * @property-read Collection|KnowledgeBase[] $knowledgeBases
 * @property-read Collection|Channel[] $channels
 * @property-read Collection|Contact[] $contacts
 * @property-read Collection|Conversation[] $conversations
 * @property-read Collection|AttributeDefinition[] $attributeDefinitions
 *
 * @method static \Database\Factories\WorkspaceFactory<self> factory($count = null, $state = [])
 */
class Workspace extends Model
{
    /**
     * 单租户运行时上下文，保留旧工作区调用面的兼容数据。
     */
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'workspaces';

    protected $guarded = [];

    protected $fillable = [
        'id',
        'name',
        'slug',
        'logo_id',
        'owner_id',
        'knowledge_embedding_model_id',
        'knowledge_rerank_model_id',
        'knowledge_summary_model_id',
        'knowledge_embedding_dimension',
        'knowledge_vector_index_enabled',
        'knowledge_raptor_index_enabled',
        'knowledge_chunking_strategy',
        'knowledge_chunk_max_tokens',
        'knowledge_chunk_overlap_tokens',
    ];

    protected $appends = [
        'logo_url',
    ];

    /**
     * 构造单租户运行时上下文对象。
     */
    public static function current(): self
    {
        /** @var GeneralSettings $generalSettings */
        $generalSettings = app(GeneralSettings::class);
        $generalSettings->refresh();

        /** @var KnowledgeSettings $knowledgeSettings */
        $knowledgeSettings = app(KnowledgeSettings::class);
        $knowledgeSettings->refresh();

        $workspace = new self([
            'id' => 'single',
            'name' => $generalSettings->name ?? config('app.name', 'HelmDesk'),
            'slug' => 'admin',
            'logo_id' => $generalSettings->logo_id,
            'owner_id' => User::query()->where('is_super_admin', true)->value('id'),
            'knowledge_embedding_model_id' => $knowledgeSettings->embedding_model_id,
            'knowledge_rerank_model_id' => $knowledgeSettings->rerank_model_id,
            'knowledge_summary_model_id' => $knowledgeSettings->summary_model_id,
            'knowledge_embedding_dimension' => $knowledgeSettings->embedding_dimension,
            'knowledge_vector_index_enabled' => $knowledgeSettings->vector_index_enabled,
            'knowledge_raptor_index_enabled' => $knowledgeSettings->raptor_index_enabled,
            'knowledge_chunking_strategy' => $knowledgeSettings->chunkingStrategy(),
            'knowledge_chunk_max_tokens' => $knowledgeSettings->chunk_max_tokens,
            'knowledge_chunk_overlap_tokens' => $knowledgeSettings->chunk_overlap_tokens,
        ]);

        return $workspace;
    }

    /**
     * 返回字段类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'knowledge_vector_index_enabled' => 'boolean',
            'knowledge_raptor_index_enabled' => 'boolean',
            'knowledge_embedding_dimension' => 'integer',
            'knowledge_chunking_strategy' => KnowledgeChunkingStrategy::class,
            'knowledge_chunk_max_tokens' => 'integer',
            'knowledge_chunk_overlap_tokens' => 'integer',
        ];
    }

    /**
     * 返回当前工作区启用的知识库索引策略。
     *
     * @return list<KnowledgeIndexingStrategy>
     */
    public function enabledKnowledgeIndexingStrategies(): array
    {
        $strategies = [];

        if ($this->knowledge_vector_index_enabled) {
            $strategies[] = KnowledgeIndexingStrategy::Vector;
        }

        if ($this->knowledge_raptor_index_enabled) {
            $strategies[] = KnowledgeIndexingStrategy::Raptor;
        }

        return $strategies;
    }

    /**
     * 判断工作区知识库配置是否启用指定索引策略。
     */
    public function hasKnowledgeIndexingStrategy(KnowledgeIndexingStrategy $strategy): bool
    {
        return in_array($strategy, $this->enabledKnowledgeIndexingStrategies(), true);
    }

    /**
     * 返回系统用户查询。
     *
     * @return Builder<User>
     */
    public function users(): Builder
    {
        return User::query();
    }

    /**
     * 工作区所有者。
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function knowledgeEmbeddingModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'knowledge_embedding_model_id');
    }

    public function knowledgeRerankModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'knowledge_rerank_model_id');
    }

    public function knowledgeSummaryModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'knowledge_summary_model_id');
    }

    /**
     * 工作区 Logo 附件。
     */
    public function logo()
    {
        return $this->morphOne(Attachment::class, 'attachable');
    }

    /**
     * 返回系统联系人和会话标签查询。
     *
     * @return Builder<Tag>
     */
    public function tags(): Builder
    {
        return Tag::query();
    }

    /**
     * 返回系统标签组查询。
     *
     * @return Builder<TagGroup>
     */
    public function tagGroups(): Builder
    {
        return TagGroup::query();
    }

    /**
     * 返回系统大模型供应商查询。
     *
     * @return Builder<AiProvider>
     */
    public function aiProviders(): Builder
    {
        return AiProvider::query();
    }

    /**
     * 返回系统 MCP 服务查询。
     *
     * @return Builder<McpServer>
     */
    public function mcpServers(): Builder
    {
        return McpServer::query();
    }

    /**
     * 返回系统翻译供应商查询。
     *
     * @return Builder<TranslationProvider>
     */
    public function translationProviders(): Builder
    {
        return TranslationProvider::query();
    }

    /**
     * 返回系统知识库查询。
     *
     * @return Builder<KnowledgeBase>
     */
    public function knowledgeBases(): Builder
    {
        return KnowledgeBase::query();
    }

    /**
     * 返回系统访客接入渠道查询。
     *
     * @return Builder<Channel>
     */
    public function channels(): Builder
    {
        return Channel::query();
    }

    /**
     * 返回系统联系人查询。
     *
     * @return Builder<Contact>
     */
    public function contacts(): Builder
    {
        return Contact::query();
    }

    /**
     * 返回系统接待会话查询。
     *
     * @return Builder<Conversation>
     */
    public function conversations(): Builder
    {
        return Conversation::query();
    }

    /**
     * 返回系统自定义属性定义查询。
     *
     * @return Builder<AttributeDefinition>
     */
    public function attributeDefinitions(): Builder
    {
        return AttributeDefinition::query();
    }

    /**
     * 获取工作区 Logo 展示地址。
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->logo?->full_url ?? asset('images/workspace.png'),
        );
    }

    /**
     * 注册工作区 slug 默认值。
     */
    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            if (empty($workspace->slug)) {
                $workspace->slug = $workspace->id;
            }
        });
    }

    /**
     * 兼容旧调用面，把单租户上下文更新同步到系统设置。
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = [])
    {
        if (! $this->isRuntimeContext()) {
            return parent::update($attributes, $options);
        }

        $this->fill($attributes);
        $this->syncRuntimeSettings();

        return true;
    }

    /**
     * 兼容旧调用面，把单租户上下文保存同步到系统设置。
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        if (! $this->isRuntimeContext()) {
            return parent::save($options);
        }

        $this->syncRuntimeSettings();

        return true;
    }

    /**
     * 重新读取单租户运行时上下文字段。
     */
    public function refresh()
    {
        if (! $this->isRuntimeContext()) {
            return parent::refresh();
        }

        $current = self::current();
        $this->setRawAttributes($current->getAttributes(), true);
        $this->setRelations([]);

        return $this;
    }

    /**
     * 返回最新的单租户运行时上下文。
     *
     * @param  array<int|string, mixed>|string  $with
     */
    public function fresh($with = [])
    {
        if (! $this->isRuntimeContext()) {
            return parent::fresh($with);
        }

        return self::current();
    }

    /**
     * 判断当前模型是否只是单租户兼容上下文，而不是数据库记录。
     */
    private function isRuntimeContext(): bool
    {
        return ! $this->exists && ((string) ($this->getAttribute('id') ?? 'single')) === 'single';
    }

    /**
     * 把旧工作区字段映射到单租户系统设置。
     */
    private function syncRuntimeSettings(): void
    {
        /** @var GeneralSettings $generalSettings */
        $generalSettings = app(GeneralSettings::class);
        $generalSettings->refresh();

        if (array_key_exists('name', $this->attributes)) {
            $generalSettings->name = (string) $this->getAttribute('name');
        }
        if (array_key_exists('logo_id', $this->attributes)) {
            $generalSettings->logo_id = $this->nullableStringAttribute('logo_id');
        }
        $generalSettings->save();

        /** @var KnowledgeSettings $knowledgeSettings */
        $knowledgeSettings = app(KnowledgeSettings::class);
        $knowledgeSettings->refresh();

        if (array_key_exists('knowledge_embedding_model_id', $this->attributes)) {
            $knowledgeSettings->embedding_model_id = $this->nullableStringAttribute('knowledge_embedding_model_id');
        }
        if (array_key_exists('knowledge_rerank_model_id', $this->attributes)) {
            $knowledgeSettings->rerank_model_id = $this->nullableStringAttribute('knowledge_rerank_model_id');
        }
        if (array_key_exists('knowledge_summary_model_id', $this->attributes)) {
            $knowledgeSettings->summary_model_id = $this->nullableStringAttribute('knowledge_summary_model_id');
        }
        if (array_key_exists('knowledge_embedding_dimension', $this->attributes)) {
            $knowledgeSettings->embedding_dimension = $this->getAttribute('knowledge_embedding_dimension') === null
                ? null
                : (int) $this->getAttribute('knowledge_embedding_dimension');
        }
        if (array_key_exists('knowledge_vector_index_enabled', $this->attributes)) {
            $knowledgeSettings->vector_index_enabled = (bool) $this->getAttribute('knowledge_vector_index_enabled');
        }
        if (array_key_exists('knowledge_raptor_index_enabled', $this->attributes)) {
            $knowledgeSettings->raptor_index_enabled = (bool) $this->getAttribute('knowledge_raptor_index_enabled');
        }
        if (array_key_exists('knowledge_chunking_strategy', $this->attributes)) {
            $strategy = $this->getAttribute('knowledge_chunking_strategy');
            $knowledgeSettings->chunking_strategy = $strategy instanceof KnowledgeChunkingStrategy
                ? $strategy->value
                : (string) $strategy;
        }
        if (array_key_exists('knowledge_chunk_max_tokens', $this->attributes)) {
            $knowledgeSettings->chunk_max_tokens = (int) $this->getAttribute('knowledge_chunk_max_tokens');
        }
        if (array_key_exists('knowledge_chunk_overlap_tokens', $this->attributes)) {
            $knowledgeSettings->chunk_overlap_tokens = (int) $this->getAttribute('knowledge_chunk_overlap_tokens');
        }

        $knowledgeSettings->save();
    }

    /**
     * 返回可写入设置表的可空字符串字段。
     */
    private function nullableStringAttribute(string $key): ?string
    {
        $value = $this->getAttribute($key);

        return filled($value) ? (string) $value : null;
    }
}
