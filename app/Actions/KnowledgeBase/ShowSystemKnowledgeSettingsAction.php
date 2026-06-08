<?php

namespace App\Actions\KnowledgeBase;

use App\Data\AiRuntime\AiModelOptionData;
use App\Data\EnumOptionData;
use App\Data\KnowledgeBase\ShowSystemKnowledgeSettingsPagePropsData;
use App\Data\KnowledgeBase\SystemKnowledgeSettingsData;
use App\Enums\AiModelType;
use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\UserPermission;
use App\Models\AiModel;
use App\Settings\KnowledgeSettings;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 返回系统知识库设置页面，承载统一检索配置与可用嵌入模型、分段策略选项。
 * 重排 / 摘要模型改由全局用途池路由，本页只 pin 嵌入模型。
 * 作为系统设置二级菜单「知识库设置」的入口，对应 resources/js/pages/systemSettings/knowledgeSettings/Index.vue。
 */
class ShowSystemKnowledgeSettingsAction
{
    use AsAction;

    /**
     * 注入系统检索配置。
     */
    public function __construct(
        private readonly KnowledgeSettings $settings,
    ) {}

    /**
     * 组装知识库设置页面 props：当前检索配置 + 嵌入模型与分段策略选项。
     */
    public function handle(): ShowSystemKnowledgeSettingsPagePropsData
    {
        $this->settings->refresh();

        return new ShowSystemKnowledgeSettingsPagePropsData(
            settings: SystemKnowledgeSettingsData::fromSettings($this->settings),
            embedding_model_options: $this->activeEmbeddingModelOptions(),
            chunking_strategy_options: EnumOptionData::fromCases(KnowledgeChunkingStrategy::cases()),
        );
    }

    /**
     * 直接查询启用中的嵌入模型（供应商存在即可选），生成 pin 用的下拉选项。
     *
     * @return list<AiModelOptionData>
     */
    private function activeEmbeddingModelOptions(): array
    {
        return AiModel::query()
            ->with('provider')
            ->where('type', AiModelType::Embedding->value)
            ->where('is_active', true)
            ->whereHas('provider')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (AiModel $model): AiModelOptionData => AiModelOptionData::fromModel($model))
            ->all();
    }

    /**
     * 渲染系统知识库设置页面。
     */
    public function asController(): Response
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsView);

        return Inertia::render('systemSettings/knowledgeSettings/Index', $this->handle()->toArray());
    }
}
