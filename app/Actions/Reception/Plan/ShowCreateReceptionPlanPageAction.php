<?php

namespace App\Actions\Reception\Plan;

use App\Data\EnumOptionData;
use App\Data\Reception\Plan\CreateReceptionPlanPagePropsData;
use App\Data\SystemUserContextData;
use App\Enums\ReceptionPersonaTone;
use App\Enums\UserPermission;
use App\Models\SystemContext;
use App\Services\AiRuntime\AiModelResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 渲染创建接待方案页（Create.vue）。
 * 仅提供基础信息表单所需的模型与语气风格选项；其余配置在创建后于详情页完善。
 */
class ShowCreateReceptionPlanPageAction
{
    use AsAction;

    public function __construct(
        private readonly AiModelResolver $resolver,
    ) {}

    /**
     * 组装创建页表单选项。
     */
    public function handle(SystemContext $systemContext): CreateReceptionPlanPagePropsData
    {
        return new CreateReceptionPlanPagePropsData(
            llm_model_options: $this->resolver->getActiveLlmModelOptions($systemContext),
            persona_tone_options: EnumOptionData::fromCases(ReceptionPersonaTone::cases()),
        );
    }

    /**
     * Controller 入口：鉴权后渲染创建页。
     */
    public function asController(Request $request): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::ReceptionPlansCreate);

        return Inertia::render('reception/plans/Create', $this->handle($systemContext)->toArray());
    }
}
