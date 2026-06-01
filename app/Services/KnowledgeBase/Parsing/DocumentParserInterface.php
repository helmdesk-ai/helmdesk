<?php

namespace App\Services\KnowledgeBase\Parsing;

/**
 * 单种文档格式的解析器；DocumentParserManager 按 mime + 扩展名挑出第一个 supports() 命中的解析器。
 *
 * 各实现应当：
 *  - 只读访问磁盘文件；不下载、不写日志；
 *  - 不调用任何外部进程；
 *  - 异常情况下抛 \RuntimeException，由上层 Action 翻译成 parse_error 写库。
 */
interface DocumentParserInterface
{
    /**
     * 是否能处理给定 mime/扩展名的文件。两者都可能为空。
     *
     * 返回 true 后 DocumentParserManager 会立即用这个解析器，所以更严格的判定（例如同时校验 mime 与扩展名）
     * 应该写在前面注册的解析器里；通用 Text 兜底解析器应放在最后。
     */
    public function supports(?string $mimeType, ?string $extension): bool;

    /**
     * 解析磁盘上的文件，返回归一化的 Markdown + 元数据。
     *
     * @throws \RuntimeException 解析失败、文件不存在、格式不支持等。
     */
    public function parse(string $absoluteFilePath, ?string $mimeType = null, ?string $extension = null): ParsedDocument;
}
