<?php

namespace App\Services\KnowledgeBase\Parsing;

use RuntimeException;

/**
 * 纯文本 / Markdown 解析器，作为兜底实现。
 *
 * supports() 对 text/markdown、txt/md/markdown/text 等显式返回 true，
 * 同时对 mime 与扩展名都缺失 / 未知的情况也接收（避免上层无解析器可用）。
 */
class TextDocumentParser implements DocumentParserInterface
{
    private const KNOWN_EXTENSIONS = ['txt', 'md', 'markdown', 'text', 'log', 'csv'];

    private const TEXT_MIME_PREFIXES = ['text/'];

    public function supports(?string $mimeType, ?string $extension): bool
    {
        if ($extension !== null && in_array($extension, self::KNOWN_EXTENSIONS, true)) {
            return true;
        }

        if ($mimeType !== null) {
            foreach (self::TEXT_MIME_PREFIXES as $prefix) {
                if (str_starts_with($mimeType, $prefix)) {
                    return true;
                }
            }
        }

        return $mimeType === null && $extension === null;
    }

    public function parse(string $absoluteFilePath, ?string $mimeType = null, ?string $extension = null): ParsedDocument
    {
        $raw = @file_get_contents($absoluteFilePath);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Failed to read text file: %s', $absoluteFilePath));
        }

        $markdown = $this->normalizeNewlines($raw);

        return new ParsedDocument(
            markdown: $markdown,
            contentFormat: 'markdown',
            metadata: [
                'parser' => 'text',
                'mime_type' => $mimeType,
                'extension' => $extension,
                'byte_size' => strlen($raw),
            ],
        );
    }

    private function normalizeNewlines(string $raw): string
    {
        $stripped = preg_replace("/^\xEF\xBB\xBF/", '', $raw) ?? $raw;

        return str_replace(["\r\n", "\r"], "\n", $stripped);
    }
}
