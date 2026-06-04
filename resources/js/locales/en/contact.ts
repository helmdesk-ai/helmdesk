/**
 * 文件说明：英文语言包，页面通过 useI18n 按中文 key 读取英文文案。
 */
// 联系人模块（英文）
export default {
  // 列表 / 通用
  查看和管理联系人信息: 'View and manage contact information',
  暂无联系人: 'No contacts yet',
  最后活跃: 'Last active',
  访客: 'Visitor',
  类型: 'Type',
  手机号: 'Phone',
  国家码: 'Country code',
  请输入有效的手机号: 'Please enter a valid phone number',
  请输入有效的邮箱地址: 'Please enter a valid email address',

  // List filters & actions
  基本: 'Basic',
  属性: 'Attributes',
  更多操作: 'More actions',
  无标签: 'Untagged',
  清空全部: 'Clear all',
  重点客户: 'Important customer',
  仅重点客户: 'Important customers only',
  标为重点客户: 'Mark important',
  取消重点客户: 'Unmark important',

  // 合并
  合并: 'Merge',
  '合并中...': 'Merging...',
  合并并删除: 'Merge and delete',
  当前联系人: 'Current contact',
  保留: 'Keep',
  确认合并: 'Confirm merge',
  '确认合并联系人？': 'Merge contacts?',
  选择要合并的联系人: 'Select a contact to merge',

  // 删除确认
  '确认删除联系人？': 'Delete contact?',
  '删除后该联系人会被移到回收站，可随时恢复；历史会话和资料会保留。':
    'After deletion, the contact will be moved to the recycle bin and can be restored later. Historical conversations and profile data will be kept.',
  '确认恢复联系人？': 'Restore contact?',
  '恢复后将重新出现在联系人列表中。':
    'The contact will return to the contacts list after restoring.',

  // 详情抽屉
  联系人详情: 'Contact details',
  编辑联系人: 'Edit contact',
  其他信息: 'Other information',
  时区: 'Timezone',
  语言: 'Language',
  国家: 'Country',
  城市: 'City',
  默认: 'Default',

  // 身份标识
  身份标识: 'Identity',
  暂无身份标识: 'No identities',
  添加: 'Add',
  添加身份标识: 'Add identity',
  替换: 'Replace',
  替换身份标识: 'Replace identity',
  新值: 'New value',
  当前值: 'Current value',
  值: 'Value',
  命名空间: 'Namespace',
  '确认删除身份标识？': 'Delete identity?',

  // AI 画像
  'AI 画像': 'AI profile',
  '暂无 AI 画像数据': 'No AI profile data',

  // 操作记录 / 活动日志
  操作记录: 'Activity log',
  暂无操作记录: 'No activity logs',
  执行人: 'Operator',
  系统: 'System',
  已记录一次操作: 'Recorded an activity',
  系统创建了联系人: 'System created contact',
  已创建联系人: 'Created contact',
  已更新联系人: 'Updated contact',
  已标为重点客户: 'Marked as important',
  已取消重点客户: 'Unmarked as important',
  已删除联系人: 'Deleted contact',
  已恢复联系人: 'Restored contact',
  已添加身份标识: 'Added identity',
  已替换身份标识: 'Replaced identity',
  已删除身份标识: 'Deleted identity',
  已合并到联系人: 'Merged into contact',
  已合并到其他联系人: 'Merged into another contact',
  已合并联系人: 'Merged contact',
  已合并一个联系人: 'Merged a contact',
  初始身份标识: 'Initial identity',
  新增身份标识: 'New identity',
  替换后身份标识: 'Identity after replacement',
  删除身份标识: 'Removed identity',
  迁移身份标识: 'Migrated identity',
  未记录身份标识: 'No identity recorded',
  此联系人已进入回收站: 'Contact moved to trash',
  此联系人已从回收站恢复: 'Contact restored from trash',
  等: 'etc.',
  项: 'items',

  // 回收站
  联系人回收站: 'Contacts trash',
  查看已删除的联系人并可恢复: 'View deleted contacts and restore them',
  暂无已删除的联系人: 'No deleted contacts',
  删除于: 'Deleted at',
} as const;
