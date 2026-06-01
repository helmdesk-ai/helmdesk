<?php

return [
    'bridge' => [
        'not_configured' => '知识库 Go 运行时桥接未配置，请联系运维确认 services.go_runtime.base_url。',
        'unavailable' => '知识库 Go 运行时桥接当前不可用，请稍后重试。',
        'invalid_response' => '知识库 Go 运行时返回的响应不可解析。',
        'request_failed' => '知识库 Go 运行时调用失败。',
    ],
    'embed' => [
        'succeeded' => '索引内容生成成功。',
        'model_unavailable' => '嵌入模型当前不可用。',
        'failed' => '索引内容生成失败。',
    ],
    'summarize' => [
        'succeeded' => '摘要生成成功。',
        'model_unavailable' => '摘要模型当前不可用。',
        'failed' => '摘要生成失败。',
    ],
    'rerank' => [
        'succeeded' => '重排序完成。',
        'model_unavailable' => '重排序模型当前不可用，将跳过本次重排。',
        'failed' => '重排序调用失败，将跳过本次重排。',
    ],
];
