<?php

namespace App\Actions\KnowledgeBase\Document;

use App\Actions\Attachment\DeleteAttachmentAction;
use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Services\KnowledgeBase\KnowledgeFullTextRepository;
use App\Services\KnowledgeBase\KnowledgeNodeRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除知识库文档：同步移除原始附件，并清空 sqlite_rag 上的全文索引、向量节点与大纲，
 * 避免主库记录已删除但 RAG 库里留下孤儿数据。
 */
class DeleteKnowledgeDocumentAction
{
    use AsAction;

    /**
     * 注入附件删除 Action 与两个 RAG 仓库，前者清原文件，后者清节点 / 向量 / 全文与大纲。
     */
    public function __construct(
        private readonly DeleteAttachmentAction $deleteAttachment,
        private readonly KnowledgeFullTextRepository $fullText,
        private readonly KnowledgeNodeRepository $nodes,
    ) {}

    /**
     * 删除指定文档及其在 RAG 库里的全部派生数据。
     */
    public function handle(KnowledgeDocument $document): void
    {
        $document->loadMissing('originalFile');

        $this->fullText->purgeForDocument($document);
        $this->nodes->purgeAllForDocument($document);

        if ($document->originalFile) {
            $this->deleteAttachment->handle($document->originalFile);
        }

        $document->delete();
    }

    /**
     * 处理「删除文档」按钮的提交，并跳回当前知识库 / 分组视图。
     */
    public function asController(Request $request, string $knowledgeBase, string $document): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::KnowledgeBasesDelete);

        $kb = KnowledgeBase::query()
            ->findOrFail($knowledgeBase);

        $documentModel = KnowledgeDocument::query()
            ->where('knowledge_base_id', $kb->id)
            ->findOrFail($document);

        $groupId = filled($documentModel->group_id) ? (string) $documentModel->group_id : null;

        $this->handle($documentModel);

        $query = ['kb' => $kb->id];
        if ($groupId !== null) {
            $query['group'] = $groupId;
        }

        return redirect()->route('admin.manage.knowledge-bases.index', [
            ...$query,
        ]);
    }
}
