<?php

namespace App\Services\Search;

use App\Models\ConversationMessage;
use Laravel\Scout\Builder;
use TeamTNT\TNTSearch\TNTSearch;

/**
 * 构建会话消息内容搜索查询。
 */
class ConversationMessageSearch
{
    private const TNT_CANDIDATE_LIMIT = 10000;

    /**
     * 注入 TNT Boolean 查询编译器。
     */
    public function __construct(
        private readonly TntBooleanQueryCompiler $queryCompiler,
    ) {}

    /**
     * 返回消息内容搜索的 Scout 查询构造器。
     *
     * @return Builder<ConversationMessage>
     */
    public function query(string $search): Builder
    {
        if (config('scout.driver') !== 'tntsearch') {
            return ConversationMessage::search($search);
        }

        return ConversationMessage::search(
            $this->queryCompiler->compile($search),
            $this->booleanSearchCallback(),
        );
    }

    /**
     * 返回第一段命中的文本。
     *
     * @param  list<string>  $texts
     */
    public function matchingText(string $search, array $texts): ?string
    {
        $queryTokens = $this->queryCompiler->tokens($search);
        if ($queryTokens === []) {
            return null;
        }

        foreach ($texts as $text) {
            if ($this->textMatchesTokens($text, $queryTokens)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * 判断文本集合是否匹配搜索词。
     *
     * @param  list<string>  $texts
     */
    public function matches(string $search, array $texts): bool
    {
        $queryTokens = $this->queryCompiler->tokens($search);
        if ($queryTokens === [] || $texts === []) {
            return false;
        }

        foreach ($texts as $text) {
            if ($this->textMatchesTokens($text, $queryTokens)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断单段文本是否包含全部查询 token。
     *
     * @param  list<string>  $queryTokens
     */
    private function textMatchesTokens(string $text, array $queryTokens): bool
    {
        $textTokens = $this->queryCompiler->tokens($text);
        $tokenSet = array_fill_keys($textTokens, true);

        foreach ($queryTokens as $token) {
            if (! isset($tokenSet[$token])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 返回 TNTSearch Boolean 查询回调。
     */
    private function booleanSearchCallback(): callable
    {
        return static function (TNTSearch $tnt, string $query): array {
            $result = $tnt->searchBoolean($query, self::TNT_CANDIDATE_LIMIT);

            $result['docScores'] = array_fill_keys($result['ids'], 0.0);

            return $result;
        };
    }
}
