<?php

namespace App\Models;

use Database\Factories\CannedReplyFactory;
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
 * @property Carbon|null $deleted_at
 * @property string|null $user_id
 * @property string $name
 * @property string|null $shortcut
 * @property string $content
 * @property int $usage_count
 * @property Carbon|null $last_used_at
 * @property array|null $metadata
 * @property string|null $created_by_user_id
 * @property string|null $updated_by_user_id
 * @property mixed $use_factory
 * @property int|null $owners_count
 * @property-read User|null $owner
 *
 * @method static \Database\Factories\CannedReplyFactory<self> factory($count = null, $state = [])
 */
class CannedReply extends Model
{
    /**
     * 快捷回复模版，承载系统共享或客服个人沉淀的可复用回复内容。
     */

    /** @use HasFactory<CannedReplyFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /**
     * 字段类型转换：JSON 元数据，时间戳。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_used_at' => 'datetime',
            'usage_count' => 'integer',
        ];
    }

    /**
     * 个人模版的归属用户；系统共享时为 null。
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * 是否为系统共享模版（非个人）。
     */
    public function isWorkspaceShared(): bool
    {
        return $this->user_id === null;
    }

    /**
     * 是否归属于指定用户的个人模版。
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->user_id !== null && (string) $this->user_id === (string) $user->id;
    }
}
