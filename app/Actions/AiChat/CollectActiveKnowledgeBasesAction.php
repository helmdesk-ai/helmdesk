<?php

namespace App\Actions\AiChat;

use App\Models\KnowledgeBase;
use App\Models\Workspace;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 收集当前 workspace 下"可供 Agent 检索"的知识库列表。
 *
 * Go 侧据此向 LLM 渲染 knowledge_search 工具的描述与白名单：
 *  - 没有任何知识库 → 不挂 knowledge_search 工具，避免 LLM 看到空白单元；
 *  - 有知识库 → 工具描述里列出 (id, name, description)，让 LLM 自己挑；
 *  - knowledge_base_ids 必须落在白名单内，PHP 侧 SearchKnowledgeBaseAction 会再校验一次。
 */
class CollectActiveKnowledgeBasesAction
{
    use AsAction;

    /**
     * @return list<array{id: string, name: string, description: string}>
     */
    public function handle(Workspace $workspace): array
    {
        $knowledgeBases = KnowledgeBase::query()
            ->orderBy('created_at')
            ->get(['id', 'name', 'description']);

        $payload = [];
        foreach ($knowledgeBases as $knowledgeBase) {
            $payload[] = [
                'id' => (string) $knowledgeBase->id,
                'name' => (string) $knowledgeBase->name,
                'description' => (string) ($knowledgeBase->description ?? ''),
            ];
        }

        return $payload;
    }
}
