<?php

namespace App\Actions\Reception\Plan;

use App\Data\Reception\ListReceptionPlanTrashPagePropsData;
use App\Data\Reception\ReceptionPlanData;
use App\Data\SimplePaginationData;
use App\Data\SystemUserContextData;
use App\Models\ReceptionPlan;
use App\Models\SystemContext;
use App\Services\AiRuntime\AiModelResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 渲染接待方案回收站页（Trash.vue）。
 * 分页展示当前系统已删除的接待方案，供查看与恢复。
 */
class ListReceptionPlanTrashAction
{
    use AsAction;

    /**
     * 回收站分页每页数量。
     */
    private const PER_PAGE = 15;

    public function __construct(
        private readonly AiModelResolver $resolver,
    ) {}

    /**
     * 组装回收站页 props：分页的已删除方案。
     */
    public function handle(SystemContext $systemContext, int $page = 1): ListReceptionPlanTrashPagePropsData
    {
        $page = max(1, $page);

        $paginator = ReceptionPlan::query()
            ->onlyTrashed()
            ->latest('deleted_at')
            ->latest('updated_at')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $plans = $paginator->getCollection();
        $plans->each(fn (ReceptionPlan $plan) => $plan->setRelation('systemContext', $systemContext));

        return new ListReceptionPlanTrashPagePropsData(
            trashed_plan_list: $plans
                ->map(fn (ReceptionPlan $plan): ReceptionPlanData => ReceptionPlanData::fromModel($plan, $this->resolver))
                ->values()
                ->all(),
            trashed_plan_list_pagination: SimplePaginationData::fromPaginator($paginator),
        );
    }

    /**
     * Controller 入口：鉴权后渲染回收站页。
     */
    public function asController(Request $request): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        return Inertia::render('reception/plans/Trash', $this->handle(
            systemContext: $systemContext,
            page: (int) $request->query('page', 1),
        )->toArray());
    }
}
