<?php

namespace App\Actions\Reception\Plan;

use App\Data\WorkspaceUserContextData;
use App\Exceptions\BusinessException;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ReceptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除工作区接待方案。
 * 任何 PlanVersion 被渠道当前部署或在途会话引用时抛 BusinessException 阻止删除。
 */
class DeleteReceptionPlanAction
{
    use AsAction;

    /**
     * 删除前置校验后将 Plan 移入回收站；PlanVersion 行保留以供历史会话解析配置。
     */
    public function handle(ReceptionPlan $plan): void
    {
        $channelReferenceCount = Channel::query()
            ->where('reception_plan_id', $plan->id)
            ->count();

        if ($channelReferenceCount > 0) {
            throw new BusinessException(__('reception.messages.plan_in_use_channel', [
                'count' => $channelReferenceCount,
            ]));
        }

        $versionIds = $plan->versions()->pluck('id');

        if ($versionIds->isNotEmpty()) {
            $conversationReferenceCount = Conversation::query()
                ->whereIn('reception_plan_version_id', $versionIds)
                ->count();

            if ($conversationReferenceCount > 0) {
                throw new BusinessException(__('reception.messages.plan_in_use_conversation', [
                    'count' => $conversationReferenceCount,
                ]));
            }
        }

        $plan->delete();
    }

    /**
     * Controller 入口：鉴权 + 跳回活跃 view 列表。
     */
    public function asController(Request $request, string $slug, string $plan): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $planModel = ReceptionPlan::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($plan);

        $this->handle($planModel);

        return redirect()->route('workspace.manage.reception.plans.index', [
            'slug' => $workspace->slug,
        ]);
    }
}
