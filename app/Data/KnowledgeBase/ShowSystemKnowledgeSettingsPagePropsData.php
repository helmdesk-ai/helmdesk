<?php

namespace App\Data\KnowledgeBase;

use App\Data\AiRuntime\AiModelOptionData;
use App\Data\EnumOptionData;
use Spatie\LaravelData\Data;

/**
 * 知识库设置页 props。
 * 由 ShowSystemKnowledgeSettingsAction 返回，承载系统统一检索配置与模型、分段策略选项。
 * 对应 resources/js/pages/systemSettings/knowledgeSettings/Index.vue。
 */
class ShowSystemKnowledgeSettingsPagePropsData extends Data
{
    /**
     * @param  AiModelOptionData[]  $embedding_model_options
     * @param  AiModelOptionData[]  $rerank_model_options
     * @param  AiModelOptionData[]  $summary_model_options  供 RAPTOR 摘要选用的 LLM 列表
     * @param  EnumOptionData[]  $chunking_strategy_options  检索配置的分段策略选项
     */
    public function __construct(
        public SystemKnowledgeSettingsData $settings,
        public array $embedding_model_options,
        public array $rerank_model_options,
        public array $summary_model_options,
        public array $chunking_strategy_options,
    ) {}
}
