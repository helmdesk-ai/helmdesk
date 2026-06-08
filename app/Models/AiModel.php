<?php

namespace App\Models;

use App\Enums\AiModelPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $ai_provider_id 所属 AI 供应商，指向 ai_providers.id
 * @property string $model_id 供应商侧的模型标识，调用时下发给上游 API，如 gpt-4o
 * @property string $name
 * @property string $type 模型能力类型：llm / embedding / rerank（由 purpose 决定）
 * @property AiModelPurpose $purpose 单一运行时用途；一个模型服务多个用途时拆成多行
 * @property bool $is_active 是否启用，停用后不参与运行时取用
 * @property int $sort_order 同一用途内的优先级，升序，运行时按此主备 fallback
 * @property-read AiProvider $provider
 */
class AiModel extends Model
{
    /**
     * 全局 AI 模型，一行对应「一个模型 + 一个用途」。
     *
     * 系统级、跨工作区共享。purpose 决定进入哪个用途池，sort_order 决定同用途内的取用主备顺序。
     */
    use HasUlids;

    protected $table = 'ai_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'purpose' => AiModelPurpose::class,
            'sort_order' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }
}
