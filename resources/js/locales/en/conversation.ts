/**
 * 文件说明：英文语言包，页面通过 useI18n 按中文 key 读取英文文案。
 */
// 会话（联系人会话列表与详情，英文）
export default {
  // 列表页
  查看所有联系人的会话历史: 'View conversation history across all contacts',
  全部状态: 'All statuses',
  收件箱状态: 'Inbox status',
  全部收件箱状态: 'All inbox statuses',
  分配给: 'Assigned to',
  全部分配: 'All assignees',
  未分配: 'Unassigned',
  接待方案版本: 'Reception plan version',
  版本: 'Version',
  主题: 'Subject',
  最后消息: 'Last message',
  无主题会话: 'Untitled conversation',
  匿名访客: 'Anonymous visitor',
  '匿名访客 #{suffix}': 'Anonymous visitor #{suffix}',
  暂无会话记录: 'No conversations yet',

  // 详情抽屉
  返回会话: 'Back to conversation',
  最后消息时间: 'Last message',
  聊天记录: 'Chat history',
  仅对话: 'Messages only',
  仅事件: 'Events only',
  '加载更早记录...': 'Loading earlier records...',
  向上滚动加载更早记录: 'Scroll up to load earlier records',
  已加载全部历史: 'All history loaded',
  暂无聊天消息: 'No chat messages',
  暂无事件记录: 'No events',
  暂无时间线记录: 'No timeline entries',
  '第 {n} 次会话': 'Conversation {n}',
  进行中: 'Ongoing',
  暂无消息: 'No messages yet',
  搜索聊天记录: 'Search chat history',
  条结果: 'results',
  关闭搜索: 'Close search',
  输入关键词搜索聊天记录: 'Enter keywords to search chat history',
  '搜索中...': 'Searching...',
  未找到匹配的消息: 'No matching messages found',

  // 消息气泡 / 事件
  显示事件消息: 'Show event messages',
  隐藏事件消息: 'Hide event messages',
  事件消息已隐藏: 'Event messages are hidden',
  工具: 'Tool',
  无内容: 'No content',
  收起详情: 'Collapse details',

  // 自定义属性布尔值
  是: 'Yes',
  否: 'No',
} as const;
