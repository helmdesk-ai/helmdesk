<?php

namespace App\Data\KnowledgeBase;

use App\Data\EnumOptionData;
use App\Data\SimplePaginationData;
use Spatie\LaravelData\Data;

/**
 * 知识库列表页 props。
 * 由 ListKnowledgeBasesAction 返回，承载知识库列表、分组树和当前选中状态。
 * 检索配置已移至系统设置「知识库设置」页（ShowSystemKnowledgeSettingsPagePropsData）。
 * 对应 resources/js/pages/knowledgeBase/List.vue。
 */
class ShowKnowledgeBaseListPagePropsData extends Data
{
    /**
     * @param  KnowledgeBaseData[]  $knowledge_base_list
     * @param  ListKnowledgeDocumentItemData[]  $document_list  当前选中普通知识库 + 分组下的文档列表（已分页）
     * @param  ListKnowledgeQaEntryItemData[]  $qa_entry_list  当前选中问答知识库 + 分组下的问答列表（已分页）
     * @param  KnowledgeIndexingStrategyOptionData[]  $indexing_strategy_options
     * @param  EnumOptionData[]  $document_status_options
     * @param  EnumOptionData[]  $qa_status_options
     * @param  EnumOptionData[]  $category_options  创建入口下拉的知识库分类选项
     * @param  EnumOptionData[]  $search_mode_options  召回测试面板的检索模式选项
     */
    public function __construct(
        public array $knowledge_base_list,
        public ?KnowledgeBaseData $selected_knowledge_base,
        public ?string $selected_group_id,
        public ?string $search,
        public ?string $current_status,
        public array $document_list,
        public SimplePaginationData $document_list_pagination,
        public array $qa_entry_list,
        public SimplePaginationData $qa_entry_list_pagination,
        public array $indexing_strategy_options,
        public array $document_status_options,
        public array $qa_status_options,
        public array $category_options,
        public array $search_mode_options,
    ) {}
}
