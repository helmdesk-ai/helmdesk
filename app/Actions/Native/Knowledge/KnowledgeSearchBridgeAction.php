<?php

namespace App\Actions\Native\Knowledge;

use App\Actions\KnowledgeBase\SearchKnowledgeBaseAction;
use App\Data\KnowledgeBase\FormKnowledgeSearchData;
use App\Data\KnowledgeBase\KnowledgeSearchResultData;
use App\Enums\KnowledgeSearchMode;
use App\Exceptions\BusinessException;
use App\Models\Workspace;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Native bridge 入口：Agent 工具调用知识库检索。
 *
 * Go 工具 knowledge_search 通过 phpbridge.CallNative 调用本 Action。
 *  - 入参 4 个字段（首个参数保留兼容旧 Go 调用，单租户下忽略）：
 *      $mode              ：grep / semantic / hybrid；
 *      $knowledgeBaseIds  ：Agent 可选的知识库 ID 列表；空列表表示全部知识库；
 *      $queries           ：单条 string 或字符串数组。
 *  - 出参直接是 KnowledgeSearchResultData 的数组形式，Go 透明转发给 LLM。
 *
 * 设计意图：Bridge Action 只做"参数解构 + 错误转换"，业务逻辑都在
 * SearchKnowledgeBaseAction 里，避免重复实现，也方便 PHP 侧单测复用。
 */
class KnowledgeSearchBridgeAction
{
    use AsAction;

    public function __construct(
        private readonly SearchKnowledgeBaseAction $searchAction,
    ) {}

    /**
     * @param  list<string>  $knowledgeBaseIds
     * @param  string|list<string>  $queries
     */
    public function handle(
        string $legacyScope,
        string $mode,
        array $knowledgeBaseIds,
        string|array $queries,
    ): KnowledgeSearchResultData {
        $modeEnum = KnowledgeSearchMode::tryFrom($mode);
        if ($modeEnum === null) {
            throw new BusinessException(__('knowledge_search.errors.mode_required'));
        }

        $workspace = Workspace::current();

        // 仅接受字符串数组成员，过滤其他类型；FormKnowledgeSearchData::normalizedQueries 会再 trim 一道。
        $cleanedQueries = is_array($queries)
            ? array_values(array_filter($queries, static fn (mixed $q): bool => is_string($q)))
            : $queries;
        $cleanedKnowledgeBaseIds = array_values(array_filter(
            $knowledgeBaseIds,
            static fn (mixed $id): bool => is_string($id) && trim($id) !== '',
        ));

        $data = FormKnowledgeSearchData::from([
            'mode' => $modeEnum->value,
            'knowledge_base_ids' => $cleanedKnowledgeBaseIds,
            'query' => $cleanedQueries,
        ]);

        return $this->searchAction->handle($workspace, $data);
    }
}
