<?php

namespace App\Actions\Reception\Plan;

use App\Data\Reception\ReceptionPlanData;
use App\Data\Reception\ShowReceptionPlanListPagePropsData;
use App\Data\SimplePaginationData;
use App\Data\WorkspaceUserContextData;
use App\Models\ReceptionPlan;
use App\Models\Workspace;
use App\Services\AiRuntime\AiModelResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 渲染接待方案列表页（List.vue）。
 * 以表格形式展示当前工作区的接待方案，右上角提供创建与回收站入口；
 * 创建、编辑、回收站分别由独立页面承接，与渠道页交互保持一致。
 */
class ShowReceptionPlanIndexPageAction
{
    use AsAction;

    /**
     * 列表分页每页数量。
     */
    private const PER_PAGE = 15;

    public function __construct(
        private readonly AiModelResolver $resolver,
    ) {}

    /**
     * 组装列表页 props：分页的活跃方案。
     */
    public function handle(Workspace $workspace, int $page = 1): ShowReceptionPlanListPagePropsData
    {
        $page = max(1, $page);

        $paginator = ReceptionPlan::query()
            ->where('workspace_id', $workspace->id)
            ->latest('updated_at')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $plans = $paginator->getCollection();
        $plans->each(fn (ReceptionPlan $plan) => $plan->setRelation('workspace', $workspace));

        return new ShowReceptionPlanListPagePropsData(
            plan_list: $plans
                ->map(fn (ReceptionPlan $plan): ReceptionPlanData => ReceptionPlanData::fromModel($plan, $this->resolver))
                ->values()
                ->all(),
            plan_list_pagination: SimplePaginationData::fromPaginator($paginator),
        );
    }

    /**
     * Controller 入口：鉴权后渲染列表页。
     */
    public function asController(Request $request): Response
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        return Inertia::render('reception/plans/List', $this->handle(
            workspace: $workspace,
            page: (int) $request->query('page', 1),
        )->toArray());
    }
}
