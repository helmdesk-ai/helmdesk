<?php

namespace App\Jobs\KnowledgeDocument;

use App\Actions\KnowledgeBase\Indexing\RebuildWorkspaceKnowledgeIndexAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 工作区检索配置变更后重建索引的批量任务。
 *
 * 把"保存设置 → 遍历全部文档/问答 → 清旧索引 → 派发新的逐条 Job"这条耗时链路从 HTTP 请求里剥出来，
 * 避免后台几千条文档导致接口超时；UpdateWorkspaceKnowledgeSettingsAction 只负责派发本 Job。
 *
 * `resetVectorTables=true` 是维度变更的快速路径：vec0 虚表整体 drop，工作区内一切策略的节点也跟着清空，
 * 后续逐条索引 Job 会按需重新建表与写节点，避免在旧维度上做无意义的逐节点 vec0 清理。
 */
class RebuildWorkspaceKnowledgeIndexJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  list<string>  $documentStrategyValues  需要重建的文档侧策略（KnowledgeIndexingStrategy 枚举的 value）
     * @param  bool  $rebuildQaVectorIndex  是否一起重建 QA 问题侧向量
     * @param  bool  $resetVectorTables  是否把 vec0 虚表全量 drop 再让下游按需重建（维度变更场景）
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly array $documentStrategyValues,
        public readonly bool $rebuildQaVectorIndex,
        public readonly bool $resetVectorTables = false,
    ) {}

    /**
     * 重建工作区下文档和 QA 的索引。
     */
    public function handle(RebuildWorkspaceKnowledgeIndexAction $action): void
    {
        $action->handle(
            workspaceId: $this->workspaceId,
            documentStrategyValues: $this->documentStrategyValues,
            rebuildQaVectorIndex: $this->rebuildQaVectorIndex,
            resetVectorTables: $this->resetVectorTables,
        );
    }
}
