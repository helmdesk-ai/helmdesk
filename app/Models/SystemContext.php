<?php

namespace App\Models;

use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\KnowledgeIndexingStrategy;
use App\Settings\GeneralSettings;
use App\Settings\KnowledgeSettings;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $name
 * @property string|null $slug
 * @property string|null $logo_id
 * @property string|null $knowledge_embedding_model_id
 * @property string|null $knowledge_rerank_model_id
 * @property string|null $knowledge_summary_model_id
 * @property int|null $knowledge_embedding_dimension
 * @property bool $knowledge_vector_index_enabled
 * @property bool $knowledge_raptor_index_enabled
 * @property KnowledgeChunkingStrategy $knowledge_chunking_strategy
 * @property int $knowledge_chunk_max_tokens
 * @property int $knowledge_chunk_overlap_tokens
 * @property mixed $use_factory
 * @property-read AiModel|null $knowledgeEmbeddingModel
 * @property-read AiModel|null $knowledgeRerankModel
 * @property-read AiModel|null $knowledgeSummaryModel
 *
 * @method static \Database\Factories\SystemContextFactory<self> factory($count = null, $state = [])
 */
class SystemContext extends Model
{
    /**
     * 单租户后台的运行时上下文，字段来源于系统设置（不落库，仅内存承载）。
     */
    use HasFactory, HasUlids;

    protected $table = 'systems';

    protected $guarded = [];

    protected $fillable = [
        'id',
        'name',
        'slug',
        'logo_id',
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

        $systemContext = new self([
            'id' => 'single',
            'name' => $generalSettings->name ?? config('app.name', 'HelmDesk'),
            'slug' => 'admin',
            'logo_id' => $generalSettings->logo_id,
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

        return $systemContext;
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
     * 返回当前系统启用的知识库索引策略。
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
     * 判断系统知识库配置是否启用指定索引策略。
     */
    public function hasKnowledgeIndexingStrategy(KnowledgeIndexingStrategy $strategy): bool
    {
        return in_array($strategy, $this->enabledKnowledgeIndexingStrategies(), true);
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
     * 将运行时上下文更新同步到系统设置。
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
     * 拦截保存（含 factory()->create() 内部的 save()）：运行时上下文不落库，改写回系统设置。
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
     * 判断当前模型是否是内存中的运行时上下文。
     */
    private function isRuntimeContext(): bool
    {
        return ! $this->exists && ((string) ($this->getAttribute('id') ?? 'single')) === 'single';
    }

    /**
     * 把运行时上下文字段同步到系统设置。
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
