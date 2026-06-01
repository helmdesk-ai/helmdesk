<?php

return [
    'types' => [
        'web' => '网站',
        'telegram' => 'Telegram',
    ],
    'statuses' => [
        'active' => '已启用',
        'disabled' => '未启用',
    ],
    'web_visitor_identity_modes' => [
        'actual_receptionist' => '按实际接待方展示',
        'unified_service' => '统一展示为客服',
    ],
    'web_widget_entry_modes' => [
        'bubble' => '默认气泡',
        'custom' => '自定义入口',
    ],
    'web_widget_entry_positions' => [
        'right' => '靠网站右侧',
        'left' => '靠网站左侧',
    ],
    'web_widget_entry_styles' => [
        'system' => '系统默认',
        'custom' => '自定义',
    ],
    'web_widget_icon_sizes' => [
        'small' => '小（36*36）',
        'medium' => '中（48*48）',
        'large' => '大（52*52）',
    ],
    'defaults' => [
        'assistant_name' => 'AI 助手',
    ],
    'messages' => [
        'created' => '渠道已创建。',
        'basic_saved' => '基本信息已保存。',
        'widget_saved' => '网站嵌入设置已保存。',
        'standalone_saved' => '聊天链接设置已保存。',
        'deleted' => '渠道已删除。',
        'restored' => '渠道已恢复。',
        'status_updated' => '渠道状态已更新。',
        'active_reception_plan_required' => '需要先部署接待方案版本到该渠道。',
        'active_reception_plan_invalid' => '当前部署的接待方案版本不可用（已归档或接待智能体模型失效），请先调整渠道部署。',
        'invalid_reception_plan_version' => '请选择当前工作区内的接待方案版本。',
        'invalid_reception_plan' => '请选择当前工作区内的接待方案。',
        'reception_plan_no_usable_version' => '所选接待方案暂无可用配置，请先在接待方案中补全可用的接待智能体模型。',
        'reception_plan_version_archived' => '所选版本已归档，无法部署到渠道。请选择仍在生效的版本或重新发布。',
        'reception_plan_version_model_unavailable' => '所选接待方案的接待智能体模型不可用，请先在 AI 设置中恢复。',
        'invalid_attachment' => '图片不可用，请重新上传。',
        'entry_icon_pair_required' => '默认图标与选中图标需同时上传；都不上传则使用系统默认图标。',
    ],
    'query_params' => [
        'locale' => '向运行时传入访客语言',
        'name' => '预填访客姓名',
        'email' => '预填访客邮箱',
        'external_id' => '传入访客外部 ID',
        'ref' => '传入来源引用标识',
        'utm_source' => '传入 UTM 来源',
        'utm_medium' => '传入 UTM 渠道媒介',
        'utm_campaign' => '传入 UTM 活动名称',
    ],
    'query_param_labels' => [
        'locale' => 'locale',
        'name' => 'name',
        'email' => 'email',
        'external_id' => 'external_id',
        'ref' => 'ref',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
    ],
    'web' => [
        'param_targets' => [
            'contact_name' => '联系人姓名',
            'contact_email' => '联系人邮箱',
            'contact_phone' => '联系人手机号',
            'contact_external_id' => '联系人外部 ID',
            'contact_importance' => '重点客户标记',
            'attribute' => '自定义属性',
            'tag' => '联系人标签',
        ],
        'param_trust' => [
            'signed_only' => '仅签名访客可写',
            'always' => '任意访客可写',
        ],
        'param_write_modes' => [
            'only_if_empty' => '仅当目标为空时写入',
            'overwrite' => '覆盖现有值',
        ],
    ],
    'telegram' => [
        'webhook_registered' => 'Telegram webhook 已注册。',
        'errors' => [
            'invalid_bot_token' => 'Telegram 拒绝了该 Bot Token，请核对从 @BotFather 获取的 Token。',
            'webhook_registration_failed' => '注册 Telegram webhook 失败：:reason',
        ],
    ],
];
