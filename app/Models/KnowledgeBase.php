<?php

namespace App\Models;

use App\Enums\KnowledgeBaseCategory;
use App\Enums\KnowledgeIndexingStrategy;
use Database\Factories\KnowledgeBaseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $workspace_id
 * @property string $name
 * @property KnowledgeBaseCategory $category
 * @property string|null $avatar_id
 * @property string|null $description
 * @property mixed $use_factory
 * @property int|null $workspaces_count
 * @property int|null $avatars_count
 * @property int|null $document_groups_count
 * @property int|null $default_document_groups_count
 * @property int|null $documents_count
 * @property int|null $qa_entries_count
 * @property-read Workspace $workspace
 * @property-read Attachment|null $avatar
 * @property-read Collection|KnowledgeGroup[] $documentGroups
 * @property-read KnowledgeGroup|null $defaultDocumentGroup
 * @property-read Collection|KnowledgeDocument[] $documents
 * @property-read Collection|KnowledgeQaEntry[] $qaEntries
 *
 * @method static \Database\Factories\KnowledgeBaseFactory<self> factory($count = null, $state = [])
 */
class KnowledgeBase extends Model
{
    /**
     * 工作区知识库模型，承载文档与问答内容。
     */

    /** @use HasFactory<KnowledgeBaseFactory> */
    use HasFactory, HasUlids;

    public const DEFAULT_GROUP_NAME = '默认分组';

    protected $guarded = [];

    /**
     * 返回字段类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => KnowledgeBaseCategory::class,
        ];
    }

    /**
     * 返回当前知识库启用的索引策略。
     *
     * @return list<KnowledgeIndexingStrategy>
     */
    public function enabledIndexingStrategies(): array
    {
        $workspace = $this->relationLoaded('workspace')
            ? $this->workspace
            : $this->workspace()->first();

        return $workspace?->enabledKnowledgeIndexingStrategies() ?? [];
    }

    /**
     * 判断指定索引策略当前是否启用。
     */
    public function hasIndexingStrategy(KnowledgeIndexingStrategy $strategy): bool
    {
        return in_array($strategy, $this->enabledIndexingStrategies(), true);
    }

    /**
     * 知识库所属工作区。
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * 知识库头像附件。
     */
    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'avatar_id');
    }

    /**
     * 知识库文档分组。
     */
    public function documentGroups(): HasMany
    {
        return $this->hasMany(KnowledgeGroup::class)->whereNull('parent_id')->orderBy('sort_order');
    }

    /**
     * 知识库自动创建的默认分组。
     */
    public function defaultDocumentGroup(): HasOne
    {
        return $this->hasOne(KnowledgeGroup::class)->where('is_default', true);
    }

    /**
     * 知识库下的文档。
     */
    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    /**
     * 知识库下的问答条目。
     */
    public function qaEntries(): HasMany
    {
        return $this->hasMany(KnowledgeQaEntry::class);
    }
}
