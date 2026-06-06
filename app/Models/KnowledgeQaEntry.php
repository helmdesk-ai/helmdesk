<?php

namespace App\Models;

use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeQaEntryStatus;
use Database\Factories\KnowledgeQaEntryFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $knowledge_base_id
 * @property string $group_id
 * @property string|null $created_by_user_id
 * @property string $question
 * @property KnowledgeQaEntryStatus $status
 * @property string|null $error_message
 * @property KnowledgeDocumentIndexingStatus $vector_status
 * @property string|null $vector_error
 * @property Carbon|null $vector_indexed_at
 * @property int $sort_order
 * @property mixed $use_factory
 * @property int|null $knowledge_bases_count
 * @property int|null $groups_count
 * @property int|null $created_bies_count
 * @property int|null $similar_questions_count
 * @property int|null $answers_count
 * @property-read KnowledgeBase $knowledgeBase
 * @property-read KnowledgeGroup $group
 * @property-read User|null $createdBy
 * @property-read Collection|KnowledgeQaQuestion[] $similarQuestions
 * @property-read Collection|KnowledgeQaAnswer[] $answers
 *
 * @method static \Database\Factories\KnowledgeQaEntryFactory<self> factory($count = null, $state = [])
 */
class KnowledgeQaEntry extends Model
{
    /**
     * 问答知识库条目，主问题作为聚合根；相似问法和答案分别挂在子表。
     */

    /** @use HasFactory<KnowledgeQaEntryFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => KnowledgeQaEntryStatus::class,
            'vector_status' => KnowledgeDocumentIndexingStatus::class,
            'vector_indexed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * 根据向量阶段状态计算问答条目在列表中的整体状态。
     */
    public function deriveOverallStatus(): KnowledgeQaEntryStatus
    {
        return match ($this->vector_status) {
            KnowledgeDocumentIndexingStatus::Failed => KnowledgeQaEntryStatus::Failed,
            KnowledgeDocumentIndexingStatus::Pending => KnowledgeQaEntryStatus::Pending,
            KnowledgeDocumentIndexingStatus::Processing => KnowledgeQaEntryStatus::Indexing,
            KnowledgeDocumentIndexingStatus::Succeeded, KnowledgeDocumentIndexingStatus::Idle => KnowledgeQaEntryStatus::Indexed,
        };
    }

    /**
     * 条目归属的知识库。
     */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(KnowledgeGroup::class, 'group_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }

    public function similarQuestions(): HasMany
    {
        return $this->hasMany(KnowledgeQaQuestion::class)->orderBy('sort_order');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(KnowledgeQaAnswer::class)->orderBy('sort_order');
    }
}
