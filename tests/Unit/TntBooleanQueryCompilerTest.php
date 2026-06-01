<?php

use App\Services\Search\CjkTokenizer;
use App\Services\Search\TntBooleanQueryCompiler;

test('中文查询编译为单字 AND 表达式', function () {
    $compiler = new TntBooleanQueryCompiler(new CjkTokenizer);

    expect($compiler->tokens('你是谁'))->toBe(['你', '是', '谁'])
        ->and($compiler->compile('你是谁'))->toBe('你 是 谁');
});

test('混合查询保留非中文 token', function () {
    $compiler = new TntBooleanQueryCompiler(new CjkTokenizer);

    expect($compiler->tokens('订单 ABC-123 你是谁'))->toBe(['订', '单', 'abc-123', '你', '是', '谁']);
});
