<?php

namespace App\Data\AiProvider;

use Spatie\LaravelData\Data;

/**
 * 总后台 AI 供应商列表页 props（纯凭据，对齐翻译供应商）。
 *
 * 由 ShowAiProviderListAction 返回，传给 resources/js/pages/systemSettings/aiProviders/Index.vue：
 * 表格展示全局供应商（名称 / 品牌 / 凭据状态），承接新增 / 编辑 / 删除。模型在「AI 模型管理」页维护。
 */
class ShowAiProviderListPagePropsData extends Data
{
    public function __construct(
        /** @var AiProviderData[] */
        public array $providers,
    ) {}
}
