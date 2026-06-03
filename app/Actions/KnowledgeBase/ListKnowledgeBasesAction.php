<?php

namespace App\Actions\KnowledgeBase;

use App\Data\EnumOptionData;
use App\Data\KnowledgeBase\KnowledgeBaseData;
use App\Data\KnowledgeBase\KnowledgeIndexingStrategyOptionData;
use App\Data\KnowledgeBase\ListKnowledgeDocumentItemData;
use App\Data\KnowledgeBase\ListKnowledgeQaEntryItemData;
use App\Data\KnowledgeBase\ShowKnowledgeBaseListPagePropsData;
use App\Data\KnowledgeBase\SystemKnowledgeSettingsData;
use App\Data\SimplePaginationData;
use App\Data\SystemUserContextData;
use App\Enums\AiModelType;
use App\Enums\KnowledgeBaseCategory;
use App\Enums\KnowledgeChunkingStrategy;
use App\Enums\KnowledgeDocumentSourceType;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeQaEntryStatus;
use App\Enums\KnowledgeSearchMode;
use App\Enums\UserPermission;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeGroup;
use App\Models\KnowledgeQaEntry;
use App\Models\SystemContext;
use App\Services\AiRuntime\AiModelResolver;
use App\Settings\KnowledgeSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 返回知识库列表页面，包含知识库列表、检索配置、分组树及当前选中状态。
 * 对应 resources/js/pages/knowledgeBase/List.vue。
 */
class ListKnowledgeBasesAction
{
    use AsAction;

    /**
     * 注入用于解析可用知识库模型选项的服务。
     */
    public function __construct(
        private readonly AiModelResolver $resolver,
        private readonly KnowledgeSettings $settings,
    ) {}

    /**
     * 单页文档数量，与前端表格分页器对齐。
     */
    private const DOCUMENT_LIST_PER_PAGE = 10;

    /**
     * 查询所有知识库及其分组树，组装页面 props。
     *
     * $status 参数同时承载 KnowledgeDocumentStatus 与 KnowledgeQaEntryStatus 两种枚举的筛选值；
     * 枚举解析根据当前选中知识库的类别在 loadDocumentList / loadQaEntryList 中延迟执行，
     * 因此 handle() 入口统一使用 ?string 类型。
     */
    public function handle(SystemContext $systemContext, ?string $selectedKnowledgeBaseId = null, ?string $selectedGroupId = null, ?string $search = null, ?string $status = null, int $page = 1, int $perPage = self::DOCUMENT_LIST_PER_PAGE): ShowKnowledgeBaseListPagePropsData
    {
        $search = $this->normalizeSearch($search);
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $allKnowledgeBases = KnowledgeBase::query()
            ->with([
                'avatar',
                'documentGroups.children',
                'documentGroups.children.children',
            ])
            ->oldest('created_at')
            ->oldest('id')
            ->get();
        $allKnowledgeBases->each->setRelation('systemContext', $systemContext);
        $this->settings->refresh();

        $knowledgeBaseListData = $allKnowledgeBases
            ->map(fn (KnowledgeBase $kb) => KnowledgeBaseData::fromModel($kb))
            ->all();

        $selectedKnowledgeBaseData = null;
        $resolvedSelectedGroupId = null;
        $documentList = [];
        $documentPagination = SimplePaginationData::placeholder($perPage);
        $qaEntryList = [];
        $qaEntryPagination = SimplePaginationData::placeholder($perPage);
        if (filled($selectedKnowledgeBaseId)) {
            $selected = $allKnowledgeBases->firstWhere('id', $selectedKnowledgeBaseId);
            if ($selected) {
                $selectedKnowledgeBaseData = KnowledgeBaseData::fromModel($selected);
                if (filled($selectedGroupId)) {
                    $resolvedSelectedGroupId = $this->resolveGroupId($selected, $selectedGroupId);
                }
                if ($selected->category === KnowledgeBaseCategory::Qa) {
                    [$qaEntryList, $qaEntryPagination] = $this->loadQaEntryList($selected, $resolvedSelectedGroupId, $search, $this->resolveQaStatus($status), $page, $perPage);
                } else {
                    [$documentList, $documentPagination] = $this->loadDocumentList($selected, $resolvedSelectedGroupId, $search, $this->resolveDocumentStatus($status), $page, $perPage);
                }
            }
        }

        return new ShowKnowledgeBaseListPagePropsData(
            knowledge_base_list: $knowledgeBaseListData,
            selected_knowledge_base: $selectedKnowledgeBaseData,
            selected_group_id: $resolvedSelectedGroupId,
            search: $search,
            current_status: $status,
            document_list: $documentList,
            document_list_pagination: $documentPagination,
            qa_entry_list: $qaEntryList,
            qa_entry_list_pagination: $qaEntryPagination,
            system_knowledge_settings: SystemKnowledgeSettingsData::fromSettings($this->settings),
            embedding_model_options: $this->resolver->getKnowledgeBaseModelOptions($systemContext, AiModelType::Embedding),
            rerank_model_options: $this->resolver->getKnowledgeBaseModelOptions($systemContext, AiModelType::Rerank),
            summary_model_options: $this->resolver->getKnowledgeBaseModelOptions($systemContext, AiModelType::Llm),
            indexing_strategy_options: KnowledgeIndexingStrategyOptionData::options(),
            document_status_options: EnumOptionData::fromCases(KnowledgeDocumentStatus::cases()),
            qa_status_options: EnumOptionData::fromCases(KnowledgeQaEntryStatus::cases()),
            category_options: EnumOptionData::fromCases(KnowledgeBaseCategory::creatableCases()),
            chunking_strategy_options: EnumOptionData::fromCases(KnowledgeChunkingStrategy::cases()),
            search_mode_options: EnumOptionData::fromCases(KnowledgeSearchMode::cases()),
        );
    }

    /**
     * 分页加载当前选中知识库下的文档列表；指定父分组时包含其子分组文档，否则返回全部文档。
     *
     * @return array{0: list<ListKnowledgeDocumentItemData>, 1: SimplePaginationData}
     */
    private function loadDocumentList(KnowledgeBase $knowledgeBase, ?string $groupId, ?string $search, ?KnowledgeDocumentStatus $status, int $page, int $perPage): array
    {
        $query = KnowledgeDocument::query()
            ->where('knowledge_base_id', $knowledgeBase->id)
            ->whereIn('source_type', [
                KnowledgeDocumentSourceType::Upload,
                KnowledgeDocumentSourceType::Manual,
            ]);

        if ($groupId !== null) {
            $query->whereIn('group_id', $this->documentScopeGroupIds($knowledgeBase, $groupId));
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($search !== null) {
            $query->where('original_filename', 'like', "%{$search}%");
        }

        $paginator = $query
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $list = $paginator->getCollection()
            ->map(fn (KnowledgeDocument $document) => ListKnowledgeDocumentItemData::fromModel($document, $knowledgeBase))
            ->all();

        $pagination = SimplePaginationData::fromPaginator($paginator);

        return [$list, $pagination];
    }

    /**
     * 分页加载当前选中问答知识库下的问答列表；搜索覆盖主问题、相似问法和答案。
     *
     * @return array{0: list<ListKnowledgeQaEntryItemData>, 1: SimplePaginationData}
     */
    private function loadQaEntryList(KnowledgeBase $knowledgeBase, ?string $groupId, ?string $search, ?KnowledgeQaEntryStatus $status, int $page, int $perPage): array
    {
        $query = KnowledgeQaEntry::query()
            ->with(['similarQuestions', 'answers'])
            ->where('knowledge_base_id', $knowledgeBase->id);

        if ($groupId !== null) {
            $query->whereIn('group_id', $this->documentScopeGroupIds($knowledgeBase, $groupId));
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($search !== null) {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('question', 'like', "%{$search}%")
                    ->orWhereHas('similarQuestions', function ($questionQuery) use ($search): void {
                        $questionQuery->where('question', 'like', "%{$search}%");
                    })
                    ->orWhereHas('answers', function ($answerQuery) use ($search): void {
                        $answerQuery->where('answer', 'like', "%{$search}%");
                    });
            });
        }

        $paginator = $query
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $list = $paginator->getCollection()
            ->map(fn (KnowledgeQaEntry $entry) => ListKnowledgeQaEntryItemData::fromModel($entry))
            ->all();

        $pagination = SimplePaginationData::fromPaginator($paginator);

        return [$list, $pagination];
    }

    /**
     * 返回当前分组视图应包含的分组 ID；父分组包含直接子分组，子分组只包含自身。
     *
     * @return list<string>
     */
    private function documentScopeGroupIds(KnowledgeBase $knowledgeBase, string $groupId): array
    {
        foreach ($knowledgeBase->documentGroups as $group) {
            if ($group->id === $groupId) {
                return [
                    (string) $group->id,
                    ...$group->children
                        ->map(fn (KnowledgeGroup $child): string => (string) $child->id)
                        ->all(),
                ];
            }

            foreach ($group->children as $child) {
                if ($child->id === $groupId) {
                    return [(string) $child->id];
                }
            }
        }

        return [$groupId];
    }

    /**
     * 渲染知识库列表页面，从 URL query 解析当前选中的知识库和分组。
     */
    public function asController(Request $request): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::KnowledgeBasesView);

        return Inertia::render('knowledgeBase/List', $this->handle(
            systemContext: $systemContext,
            selectedKnowledgeBaseId: $request->query('kb'),
            selectedGroupId: $request->query('group'),
            search: $this->resolveSearch($request->query('search')),
            status: $this->resolveStatus($request->query('status')),
            page: max(1, (int) $request->query('page', 1)),
        )->toArray());
    }

    /**
     * 去除搜索词首尾空白，空串归一为 null，便于 handle() 内统一判断是否启用模糊匹配。
     */
    private function normalizeSearch(?string $search): ?string
    {
        $search = $search !== null ? trim($search) : '';

        return $search !== '' ? $search : null;
    }

    /**
     * 将 URL query 中的搜索词转换为规范化字符串；非字符串值统一回退为 null。
     */
    private function resolveSearch(mixed $search): ?string
    {
        return is_string($search) ? $this->normalizeSearch($search) : null;
    }

    /**
     * 将 URL query 中的状态筛选转换为可复用的状态值。
     */
    private function resolveStatus(mixed $status): ?string
    {
        if (! is_string($status)) {
            return null;
        }

        if (KnowledgeDocumentStatus::tryFrom($status) || KnowledgeQaEntryStatus::tryFrom($status)) {
            return $status;
        }

        return null;
    }

    /**
     * 将共享状态值转换为文档状态枚举。
     */
    private function resolveDocumentStatus(?string $status): ?KnowledgeDocumentStatus
    {
        return $status !== null ? KnowledgeDocumentStatus::tryFrom($status) : null;
    }

    /**
     * 将共享状态值转换为问答状态枚举。
     */
    private function resolveQaStatus(?string $status): ?KnowledgeQaEntryStatus
    {
        return $status !== null ? KnowledgeQaEntryStatus::tryFrom($status) : null;
    }

    /**
     * 校验 group_id 是否属于当前知识库；不属于则返回 null（前端会回退到全部文档）。
     */
    private function resolveGroupId(KnowledgeBase $knowledgeBase, string $groupId): ?string
    {
        foreach ($knowledgeBase->documentGroups as $group) {
            if ($group->id === $groupId) {
                return $group->id;
            }
            foreach ($group->children as $child) {
                if ($child->id === $groupId) {
                    return $child->id;
                }
            }
        }

        return null;
    }
}
