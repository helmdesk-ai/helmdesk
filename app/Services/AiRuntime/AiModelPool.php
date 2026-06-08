<?php

namespace App\Services\AiRuntime;

use App\Enums\AiModelPurpose;
use App\Models\AiModel;
use Illuminate\Support\Collection;

/**
 * 全局 AI 模型用途池。
 *
 * 各业务场景按「用途」从全局「启用且所属供应商凭据完整」的模型里取用。一行模型对应一个用途，
 * 同用途内按 sort_order 升序（越小越优先）主备 fallback，同序用 id 兜底。
 */
class AiModelPool
{
    /**
     * 返回某用途下可用的模型集合（按 sort_order 升序，含 provider 关联）。
     *
     * @return Collection<int, AiModel>
     */
    public function modelsForPurpose(AiModelPurpose $purpose): Collection
    {
        return AiModel::query()
            ->with('provider')
            ->where('purpose', $purpose->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(static fn (AiModel $model): bool => $model->provider !== null
                && $model->provider->hasCompleteCredentials())
            ->values();
    }

    /**
     * 该用途当前是否有可用模型。
     */
    public function hasUsable(AiModelPurpose $purpose): bool
    {
        return $this->modelsForPurpose($purpose)->isNotEmpty();
    }

    /**
     * 取该用途下优先级最高的可用模型（主模型）。
     */
    public function firstForPurpose(AiModelPurpose $purpose): ?AiModel
    {
        return $this->modelsForPurpose($purpose)->first();
    }
}
