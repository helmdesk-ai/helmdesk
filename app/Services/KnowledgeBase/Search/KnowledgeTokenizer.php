<?php

namespace App\Services\KnowledgeBase\Search;

use App\Services\Search\CjkTokenizer;

/**
 * 知识库专用分词器。
 *
 * 复用 App\Services\Search\CjkTokenizer 的中日韩 unigram + bigram、英文小写归一、
 * 数字 + 数字前缀、+86 手机号兜底等能力；在此之上叠加一份知识库语义的停用词，
 * 不要污染原 tokenizer 在联系人 / 会话搜索里的行为。
 *
 *  - indexable() 把分段后的可被 FTS 索引的"空格分隔 token 串"返回，写入 knowledge_fts.search_content；
 *  - queryTokens() 把用户查询切成 token 列表，由 FullTextRetriever 拼装 MATCH 表达式。
 */
class KnowledgeTokenizer
{
    /**
     * 知识库专用中文停用词。覆盖问候、疑问助词、低价值连接词，避免把"怎么 / 如何 / 是否 / 请问"
     * 这类高频共现词作为关键 token 进 FTS。English 停用词仍由调用方按需补充。
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        '的', '了', '吗', '呢', '哦', '啊', '呀', '哈',
        '我', '你', '它', '他', '她', '我们', '你们', '他们',
        '是', '在', '和', '与', '及', '或',
        '这', '那', '这个', '那个', '这样', '那样', '这里', '那里',
        '一个', '一种', '一些', '一下', '一次',
        '怎么', '如何', '为什么', '怎样', '是否', '能否', '可以', '请问',
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'is', 'are',
    ];

    public function __construct(
        private readonly CjkTokenizer $tokenizer,
    ) {}

    /**
     * 返回用于写入 FTS 索引的空格分隔 token 串。
     *
     *  - 始终包含 unigram + bigram（CjkTokenizer 自带），中文短查询也能稳定命中；
     *  - 默认不剔除停用词，因为命中段会照常带上停用词原文，搜索时再统一过滤。
     */
    public function indexable(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        $tokens = $this->tokenizer->tokenize($text);

        return implode(' ', $tokens);
    }

    /**
     * 返回用户查询经过分词、去停用词后的 token 列表。
     *
     * @return list<string>
     */
    public function queryTokens(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $tokens = $this->tokenizer->tokenize($query, self::STOP_WORDS);

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }
}
