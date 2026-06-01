<?php

namespace App\Services\Reception;

use App\Data\AiRuntime\ModelSelectionStatusData;
use App\Enums\ReceptionPlanVersionStatus;
use App\Models\Channel;
use App\Models\ReceptionPlanVersion;
use App\Models\Workspace;
use App\Services\AiRuntime\AiModelResolver;

/**
 * 解析渠道绑定方案当前生效版本（最新已发布版）的可用状态。
 *
 * 统一返回 ModelSelectionStatusData 让前端复用模型状态指示器：
 * - 未绑定方案：返回 null（调用方按"未配置 AI 接待"展示）
 * - 方案无可用最新版本：isValid=false / reason=reception_plan_no_usable_version
 * - 最新版默认模型失效：isValid=false / reason=reception_plan_version_model_unavailable
 * - 最新版当前可用：isValid=true
 */
class ChannelReceptionPlanVersionResolver
{
    /**
     * 注入 AI 模型解析器与版本解析器，校验方案最新版默认接待模型仍可用。
     */
    public function __construct(
        private readonly AiModelResolver $resolver,
        private readonly ChannelActivePlanVersionResolver $activePlanVersionResolver,
    ) {}

    /**
     * 解析渠道绑定方案最新已发布版本的状态；未绑定方案时返回 null。
     */
    public function resolveChannelStatus(Workspace $workspace, Channel $channel): ?ModelSelectionStatusData
    {
        if (! filled($channel->reception_plan_id)) {
            return null;
        }

        $version = $this->activePlanVersionResolver->currentVersionForChannel($channel);

        if ($version === null) {
            $plan = $channel->relationLoaded('receptionPlan') ? $channel->receptionPlan : $channel->receptionPlan()->first();

            return new ModelSelectionStatusData(
                id: (string) $channel->reception_plan_id,
                label: filled($plan?->name) ? (string) $plan->name : null,
                isValid: false,
                reason: 'reception_plan_no_usable_version',
                reason_label: __('channel.messages.reception_plan_no_usable_version'),
            );
        }

        return $this->resolveVersionStatus($workspace, $version);
    }

    /**
     * 直接对版本对象做可用性解析；用于版本列表 / 选项渲染等需要复用同一规则的场景。
     */
    public function resolveVersionStatus(Workspace $workspace, ReceptionPlanVersion $version): ModelSelectionStatusData
    {
        $label = $this->formatVersionLabel($version);

        if ($version->status === ReceptionPlanVersionStatus::Archived) {
            return new ModelSelectionStatusData(
                id: (string) $version->id,
                label: $label,
                isValid: false,
                reason: 'reception_plan_version_archived',
                reason_label: __('channel.messages.reception_plan_version_archived'),
            );
        }

        $compiled = $version->compiled_config;
        $modelId = $compiled['reception_config']['default_model']['ai_model_id'] ?? null;
        $modelId = is_string($modelId) ? $modelId : null;
        $modelStatus = $this->resolver->resolveModelStatus($workspace, $modelId);

        if (! $modelStatus->isValid) {
            return new ModelSelectionStatusData(
                id: (string) $version->id,
                label: $label,
                isValid: false,
                reason: 'reception_plan_version_model_unavailable',
                reason_label: __('channel.messages.reception_plan_version_model_unavailable'),
            );
        }

        return new ModelSelectionStatusData(
            id: (string) $version->id,
            label: $label,
            isValid: true,
        );
    }

    /**
     * 把版本所属方案格式化成可读 label，便于详情页头部呈现"当前接待方案：方案名"。
     * 版本号对运营已隐藏，label 只用方案名。
     */
    private function formatVersionLabel(ReceptionPlanVersion $version): string
    {
        $plan = $version->relationLoaded('plan') ? $version->plan : $version->plan()->first();

        return filled($plan?->name) ? (string) $plan->name : '';
    }
}
