<?php

use App\Services\KnowledgeBase\Parsing\DocumentParserManager;
use App\Services\KnowledgeBase\Parsing\TextDocumentParser;

it('使用 TextDocumentParser 处理纯文本/Markdown 文件', function () {
    $manager = new DocumentParserManager([new TextDocumentParser]);
    $path = tempnam(sys_get_temp_dir(), 'kb-');
    file_put_contents($path, "# 标题\n\n正文。");

    try {
        $parsed = $manager->parse($path, 'text/markdown', 'md');
        expect($parsed->markdown)->toContain('标题')
            ->and($parsed->contentFormat)->toBe('markdown')
            ->and($parsed->metadata['parser'])->toBe('text');
    } finally {
        @unlink($path);
    }
});

it('文件不存在时抛出 RuntimeException', function () {
    $manager = new DocumentParserManager([new TextDocumentParser]);
    expect(fn () => $manager->parse('/tmp/__definitely_not_here__.txt', 'text/plain', 'txt'))
        ->toThrow(RuntimeException::class);
});

it('无解析器命中时抛出 RuntimeException', function () {
    $manager = new DocumentParserManager([]);
    $path = tempnam(sys_get_temp_dir(), 'kb-');
    file_put_contents($path, 'hello');

    try {
        expect(fn () => $manager->parse($path, 'application/pdf', 'pdf'))
            ->toThrow(RuntimeException::class);
    } finally {
        @unlink($path);
    }
});

it('CRLF 行结束符会被归一化为 LF', function () {
    $parser = new TextDocumentParser;
    $path = tempnam(sys_get_temp_dir(), 'kb-');
    file_put_contents($path, "line1\r\nline2\r\n");

    try {
        $parsed = $parser->parse($path, 'text/plain', 'txt');
        expect($parsed->markdown)->toContain("line1\nline2")
            ->and($parsed->markdown)->not->toContain("\r");
    } finally {
        @unlink($path);
    }
});
