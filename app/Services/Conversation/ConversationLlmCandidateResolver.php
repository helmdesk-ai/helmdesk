<?php

namespace App\Services\Conversation;

use App\Enums\AiModelPurpose;
use App\Models\AiModel;
use App\Services\AiRuntime\AiModelPool;

/**
 * 解析会话级轻量 AI 任务（摘要 / 标签 / 主题等）可用的 LLM 候选模型。
 *
 * 不再读取接待方案版本里的任务模型：统一从全局 background_task 用途池按 sort_order 取用、失败轮询。
 */
class ConversationLlmCandidateResolver
{
    /**
     * 注入全局模型用途池。
     */
    public function __construct(
        private readonly AiModelPool $pool,
    ) {}

    /**
     * 返回 background_task 用途下的候选模型列表（含 provider 关联，按主备顺序）。
     *
     * @return list<AiModel>
     */
    public function resolve(): array
    {
        return $this->pool->modelsForPurpose(AiModelPurpose::BackgroundTask)->all();
    }
}
