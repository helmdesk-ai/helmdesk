<?php

namespace App\Models;

use Database\Factories\KnowledgeQaAnswerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $knowledge_qa_entry_id
 * @property string $answer
 * @property bool $is_default
 * @property bool $is_enabled
 * @property int $sort_order
 * @property mixed $use_factory
 * @property int|null $entries_count
 * @property-read KnowledgeQaEntry $entry
 *
 * @method static \Database\Factories\KnowledgeQaAnswerFactory<self> factory($count = null, $state = [])
 */
class KnowledgeQaAnswer extends Model
{
    /**
     * 问答条目的候选答案；排序最靠前且启用的答案作为默认返回内容。
     */

    /** @use HasFactory<KnowledgeQaAnswerFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeQaEntry::class, 'knowledge_qa_entry_id');
    }
}
