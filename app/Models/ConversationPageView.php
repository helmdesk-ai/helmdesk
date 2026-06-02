<?php

namespace App\Models;

use Database\Factories\ConversationPageViewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $conversation_id
 * @property string|null $contact_id
 * @property string $url
 * @property string|null $title
 * @property string|null $referrer
 * @property Carbon $viewed_at
 * @property-read Conversation $conversation
 *
 * @method static \Database\Factories\ConversationPageViewFactory<self> factory($count = null, $state = [])
 */
class ConversationPageView extends Model
{
    /**
     * 访客浏览轨迹模型：记录一次会话内访客访问过的页面，时间序列。
     */

    /** @use HasFactory<ConversationPageViewFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * 返回浏览轨迹字段的类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    /**
     * 关联轨迹所属会话。
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
