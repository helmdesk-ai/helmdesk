<?php

use Illuminate\Support\Facades\File;

test('列表空状态使用暂无对象名短文案', function (): void {
    $files = [
        'pages/reception/plans/List.vue' => [
            'expected' => [
                "t('暂无接待方案')",
            ],
        ],
        'pages/reception/plans/Trash.vue' => [
            'expected' => [
                "t('暂无已删除的接待方案')",
            ],
        ],
        'pages/reception/plans/Detail.vue' => [
            'expected' => [
                "t('暂无服务场景')",
                "t('暂无可用知识库')",
                "t('暂无可用 MCP 工具')",
            ],
        ],
        'pages/cannedReplies/Index.vue' => [
            'expected' => [
                "t('暂无快捷回复')",
                "t('暂无匹配的快捷回复')",
            ],
        ],
        'pages/inbox/CannedReplyPicker.vue' => [
            'expected' => [
                "t('暂无快捷回复')",
                "t('暂无匹配的快捷回复')",
            ],
        ],
        'pages/systemSettings/aiProviders/Index.vue' => [
            'expected' => [
                "t('暂无模型')",
                "t('暂无供应商')",
            ],
        ],
        'pages/systemSettings/mcpServers/Index.vue' => [
            'expected' => [
                "t('暂无 MCP 服务')",
            ],
        ],
        'pages/systemSettings/translationProviders/Index.vue' => [
            'expected' => [
                "t('暂无供应商')",
            ],
        ],
        'pages/channel/web/List.vue' => [
            'expected' => [
                "t('暂无网站渠道')",
            ],
        ],
        'pages/channel/web/Trash.vue' => [
            'expected' => [
                "t('暂无已删除的渠道')",
            ],
        ],
        'locales/zh-CN/system-admin.ts' => [
            'expected' => [
                '暂无接待方案',
                '暂无已删除的接待方案',
                '暂无服务场景',
                '暂无网站渠道',
                '暂无 MCP 服务',
                '暂无供应商',
            ],
        ],
        'locales/en/system-admin.ts' => [
            'expected' => [
                '暂无接待方案',
                '暂无已删除的接待方案',
                '暂无服务场景',
                '暂无网站渠道',
                '暂无 MCP 服务',
                '暂无供应商',
            ],
        ],
        'locales/zh-CN/system-settings.ts' => [
            'expected' => [
                '暂无供应商',
                '暂无模型',
            ],
        ],
        'locales/en/system-settings.ts' => [
            'expected' => [
                '暂无供应商',
                '暂无模型',
            ],
        ],
    ];

    foreach ($files as $relativePath => $assertions) {
        $contents = File::get(resource_path("js/{$relativePath}"));

        foreach ($assertions['expected'] as $expected) {
            expect($contents)->toContain($expected);
        }
    }
});
