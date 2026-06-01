<?php

namespace App\Models;

use App\Enums\ConversationTimelineEntryType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $workspace_id
 * @property string|null $contact_id
 * @property string $conversation_id
 * @property ConversationTimelineEntryType $entry_type
 * @property string $entry_id
 * @property Carbon $occurred_at
 */
class ConversationTimelineEntry extends Model
{
    /**
     * 会话时间线索引模型，只保存排序、归属和事实表指针。
     */
    use HasUlids;

    protected $guarded = [];

    /**
     * 返回时间线索引字段的类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => ConversationTimelineEntryType::class,
            'occurred_at' => 'datetime',
        ];
    }
}
