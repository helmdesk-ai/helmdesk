<?php

namespace App\Actions\KnowledgeBase\Indexing;

use App\Data\WorkspaceUserContextData;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 用户在文档列表点击"重新索引"时触发完整文档索引流水线。
 */
class ReindexKnowledgeDocumentAction
{
    use AsAction;

    /**
     * 注入文档索引流水线编排器。
     */
    public function __construct(
        private readonly DispatchKnowledgeDocumentPipelineAction $dispatcher,
    ) {}

    /**
     * 为文档重新投递解析和索引任务。
     */
    public function handle(KnowledgeDocument $document): void
    {
        $this->dispatcher->handle($document, forceReparse: true);
    }

    /**
     * 接收 POST，重新触发流水线并跳回当前列表。
     */
    public function asController(Request $request, string $slug, string $knowledgeBase, string $document): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $kb = KnowledgeBase::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($knowledgeBase);

        $documentModel = KnowledgeDocument::query()
            ->where('knowledge_base_id', $kb->id)
            ->findOrFail($document);

        $this->handle($documentModel);

        return back()->with('success', __('knowledge_base.messages.reindex_dispatched'));
    }
}
