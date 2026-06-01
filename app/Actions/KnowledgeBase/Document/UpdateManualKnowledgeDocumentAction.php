<?php

namespace App\Actions\KnowledgeBase\Document;

use App\Actions\KnowledgeBase\Indexing\DispatchKnowledgeDocumentPipelineAction;
use App\Data\KnowledgeBase\FormUpdateManualKnowledgeDocumentData;
use App\Data\WorkspaceUserContextData;
use App\Enums\KnowledgeDocumentSourceType;
use App\Exceptions\BusinessException;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 编辑手动添加的知识库文档；只允许 source_type=manual 的文档调整标题与正文。
 * 分组变更走 MoveKnowledgeDocumentAction，不在这里处理。
 */
class UpdateManualKnowledgeDocumentAction
{
    use AsAction;

    public function __construct(
        private readonly DispatchKnowledgeDocumentPipelineAction $pipeline,
    ) {}

    /**
     * 更新手动文档的标题、正文、字节数和校验和。
     * 保存手动文档内容并重新触发索引流水线。
     */
    public function handle(KnowledgeDocument $document, FormUpdateManualKnowledgeDocumentData $data): void
    {
        if ($document->source_type !== KnowledgeDocumentSourceType::Manual) {
            throw new BusinessException(__('knowledge_base.documents.errors.not_manual_editable'));
        }

        $title = trim($data->title);
        $content = $data->content;
        $contentChanged = ((string) $document->content) !== $content;

        $document->update([
            'original_filename' => $title,
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'byte_size' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'content' => $content,
        ]);

        if ($contentChanged) {
            $this->pipeline->handle($document->fresh() ?? $document, forceReparse: true);
        }
    }

    /**
     * 处理「编辑手动文档」提交并跳回当前知识库 / 分组视图。
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

        $data = FormUpdateManualKnowledgeDocumentData::from($request);

        $this->handle($documentModel, $data);

        $groupId = filled($documentModel->group_id) ? (string) $documentModel->group_id : null;
        $query = ['kb' => $kb->id];
        if ($groupId !== null) {
            $query['group'] = $groupId;
        }

        return redirect()->route('workspace.manage.knowledge-bases.index', [
            'slug' => $workspace->slug,
            ...$query,
        ]);
    }
}
