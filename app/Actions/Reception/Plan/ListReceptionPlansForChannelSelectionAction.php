<?php

namespace App\Actions\Reception\Plan;

use App\Data\Reception\Plan\ReceptionPlanOptionData;
use App\Models\Channel;
use App\Models\ReceptionPlan;
use App\Models\SystemContext;
use App\Services\AiRuntime\AiModelResolver;
use App\Services\Reception\ChannelActivePlanVersionResolver;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 列出系统内可供渠道绑定 / 会话筛选的接待方案。
 *
 * 返回全部方案，并按「最新已发布版本是否可部署」标记 is_usable + 失效原因，
 * 让前端下拉既能选可用方案，也能展示当前绑定但已失效的方案。
 */
class ListReceptionPlansForChannelSelectionAction
{
    use AsAction;

    /**
     * 注入版本解析器与 AI 模型解析器，用于判定方案最新版可用性。
     */
    public function __construct(
        private readonly ChannelActivePlanVersionResolver $activePlanVersionResolver,
        private readonly AiModelResolver $resolver,
    ) {}

    /**
     * 返回系统内全部接待方案选项（含可用性标记）。
     *
     * @return ReceptionPlanOptionData[]
     */
    public function handle(SystemContext $systemContext): array
    {
        return ReceptionPlan::query()
            ->orderBy('name')
            ->get()
            ->map(function (ReceptionPlan $plan) use ($systemContext): ReceptionPlanOptionData {
                [$isUsable, $reason, $reasonLabel] = $this->resolvePlanUsability($systemContext, $plan);

                return new ReceptionPlanOptionData(
                    id: (string) $plan->id,
                    name: (string) $plan->name,
                    is_usable: $isUsable,
                    unusable_reason: $reason,
                    unusable_reason_label: $reasonLabel,
                );
            })
            ->all();
    }

    /**
     * 解析方案最新已发布版本能否部署：无已发布版本 / 默认接待模型失效都会标记不可用。
     *
     * @return array{0: bool, 1: ?string, 2: ?string}
     */
    private function resolvePlanUsability(SystemContext $systemContext, ReceptionPlan $plan): array
    {
        $probeChannel = new Channel(['reception_plan_id' => $plan->id]);
        $version = $this->activePlanVersionResolver->currentVersionForChannel($probeChannel);

        if ($version === null) {
            return [false, 'reception_plan_no_usable_version', __('reception.plan_version_unusable_reasons.no_usable_version')];
        }

        $compiled = is_array($version->compiled_config) ? $version->compiled_config : [];
        if (! $this->resolver->hasUsableModels($systemContext, $compiled)) {
            return [false, 'reception_model_unavailable', __('reception.plan_version_unusable_reasons.reception_model_unavailable')];
        }

        return [true, null, null];
    }
}
