<?php

namespace App\Actions\KnowledgeBase;

use App\Data\SystemUserContextData;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeGroup;
use App\Models\KnowledgeQaAnswer;
use App\Models\KnowledgeQaEntry;
use App\Models\KnowledgeQaQuestion;
use App\Services\KnowledgeBase\KnowledgeFullTextRepository;
use App\Services\KnowledgeBase\KnowledgeNodeRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除系统下的知识库：清理主库的文档 / 问答 / 分组等关联记录，
 * 并同步清空 sqlite_rag 上对应的全文索引、向量节点和大纲，避免出现孤儿数据。
 */
class DeleteKnowledgeBaseAction
{
    use AsAction;

    public function __construct(
        private readonly KnowledgeFullTextRepository $fullText,
        private readonly KnowledgeNodeRepository $nodes,
    ) {}

    /**
     * 删除知识库以及其在主库 + sqlite_rag 库里的所有派生数据。
     */
    public function handle(KnowledgeBase $knowledgeBase): void
    {
        // 主库写在外层事务里，RAG 库的清理走自己的连接事务；二者解耦后失败也不会
        // 把 sqlite_rag 卷进 RDBMS 事务。先清 RAG 再清主库可以确保即使主库提交失败，
        // RAG 库的残留也能在后续重试或定时清理里被对齐。
        $this->fullText->purgeForKnowledgeBase($knowledgeBase);
        $this->nodes->purgeAllForKnowledgeBase($knowledgeBase);

        DB::transaction(function () use ($knowledgeBase): void {
            $knowledgeBase->documents()->delete();

            $qaEntryIds = KnowledgeQaEntry::query()
                ->where('knowledge_base_id', $knowledgeBase->id)
                ->pluck('id');

            if ($qaEntryIds->isNotEmpty()) {
                KnowledgeQaQuestion::query()
                    ->whereIn('knowledge_qa_entry_id', $qaEntryIds)
                    ->delete();
                KnowledgeQaAnswer::query()
                    ->whereIn('knowledge_qa_entry_id', $qaEntryIds)
                    ->delete();
                KnowledgeQaEntry::query()
                    ->whereIn('id', $qaEntryIds)
                    ->delete();
            }

            KnowledgeGroup::query()
                ->where('knowledge_base_id', $knowledgeBase->id)
                ->delete();

            $knowledgeBase->delete();
        });
    }

    /**
     * 接收删除知识库请求并返回列表页。
     */
    public function asController(Request $request, string $knowledgeBase): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        $knowledgeBaseModel = KnowledgeBase::query()
            ->findOrFail($knowledgeBase);

        $this->handle($knowledgeBaseModel);

        return redirect()->route('admin.manage.knowledge-bases.index');
    }
}
