<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $ai_provider_id
 * @property string $model_id
 * @property string $name
 * @property string $type
 * @property bool $is_active
 * @property bool $is_builtin
 * @property int $sort_order
 * @property int|null $providers_count
 * @property-read AiProvider $provider
 */
class AiModel extends Model
{
    /**
     * AI 模型模型，保存供应商下可用模型的限额和启用状态。
     */
    use HasUlids;

    protected $table = 'ai_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_builtin' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }
}
