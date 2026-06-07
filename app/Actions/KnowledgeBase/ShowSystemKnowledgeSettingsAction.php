<?php

namespace App\Actions\KnowledgeBase;

use App\Data\EnumOptionData;
use App\Data\KnowledgeBase\ShowSystemKnowledgeSettingsPagePropsData;
use App\Data\KnowledgeBase\SystemKnowledgeSettingsData;
use App\Enums\AiModelType;
use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\UserPermission;
use App\Services\AiRuntime\AiModelResolver;
use App\Settings\KnowledgeSettings;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 返回系统知识库设置页面，承载统一检索配置与可用模型、分段策略选项。
 * 作为系统设置二级菜单「知识库设置」的入口，对应 resources/js/pages/systemSettings/knowledgeSettings/Index.vue。
 */
class ShowSystemKnowledgeSettingsAction
{
    use AsAction;

    /**
     * 注入用于解析可用知识库模型选项的服务与系统检索配置。
     */
    public function __construct(
        private readonly AiModelResolver $resolver,
        private readonly KnowledgeSettings $settings,
    ) {}

    /**
     * 组装知识库设置页面 props：当前检索配置 + 模型与分段策略选项。
     */
    public function handle(): ShowSystemKnowledgeSettingsPagePropsData
    {
        $this->settings->refresh();

        return new ShowSystemKnowledgeSettingsPagePropsData(
            settings: SystemKnowledgeSettingsData::fromSettings($this->settings),
            embedding_model_options: $this->resolver->getKnowledgeBaseModelOptions(AiModelType::Embedding),
            rerank_model_options: $this->resolver->getKnowledgeBaseModelOptions(AiModelType::Rerank),
            summary_model_options: $this->resolver->getKnowledgeBaseModelOptions(AiModelType::Llm),
            chunking_strategy_options: EnumOptionData::fromCases(KnowledgeChunkingStrategy::cases()),
        );
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
