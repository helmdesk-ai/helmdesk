<?php

namespace App\Data\Reception;

use App\Data\AiRuntime\ModelSelectionStatusData;
use Spatie\LaravelData\Data;

/**
 * 接待方案模型候选项展示数据。
 * 用于接待方案表单、版本详情和回收站快照中展示主模型与备用模型的优先级和当前可用状态。
 */
class ModelCandidateData extends Data
{
    public function __construct(
        public string $ai_model_id,
        public int $priority,
        public ?string $label,
        public ModelSelectionStatusData $status,
    ) {}
}
