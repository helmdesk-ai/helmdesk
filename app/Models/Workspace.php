<?php

namespace App\Models;

use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\KnowledgeIndexingStrategy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * 工作区模型，承载租户边界、成员关系和工作区内业务配置。
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
     * 工作区成员列表。
     */
    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'nickname', 'online_status', 'last_active_at')->withTimestamps();
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
     * 工作区联系人和会话标签。
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * 工作区标签组，按适用维度（会话/联系人）归类标签。
     */
    public function tagGroups(): HasMany
    {
        return $this->hasMany(TagGroup::class);
    }

    /**
     * 工作区大模型供应商。
     */
    public function aiProviders(): HasMany
    {
        return $this->hasMany(AiProvider::class);
    }

    /**
     * 工作区已注册的 MCP 服务（外部能力来源）。
     */
    public function mcpServers(): HasMany
    {
        return $this->hasMany(McpServer::class);
    }

    /**
     * 工作区翻译供应商，按 sort_order 升序展示在设置页和被 TranslatorManager 解析。
     */
    public function translationProviders(): HasMany
    {
        return $this->hasMany(TranslationProvider::class);
    }

    /**
     * 工作区知识库列表。
     */
    public function knowledgeBases(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    /**
     * 工作区访客接入渠道。
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * 工作区联系人列表。
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * 工作区接待会话列表。
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * 工作区自定义属性定义。
     */
    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(AttributeDefinition::class);
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
}
