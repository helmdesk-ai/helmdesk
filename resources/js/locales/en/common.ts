/**
 * 文件说明：英文语言包，页面通过 useI18n 按中文 key 读取英文文案。
 */
// 通用（英文）
export default {
  // 通用
  '加载中...': 'Loading...',
  发生错误: 'An error occurred',
  选择图片: 'Choose image',
  未选择任何文件: 'No file chosen',
  客服: 'Teammate',
  在线: 'Online',
  离线: 'Offline',

  // 状态 / 安全
  已启用: 'Enabled',
  未启用: 'Not enabled',
  重置两步验证: 'Reset two-factor Auth',
  '重置中...': 'Resetting...',

  // 提示通知
  成功: 'Success',
  错误: 'Error',
  警告: 'Warning',
  提示: 'Info',
  通知: 'Notice',

  // 通用操作
  保存: 'Save',
  '保存中...': 'Saving...',
  继续: 'Continue',
  取消: 'Cancel',
  确认: 'Confirm',
  关闭: 'Close',
  返回: 'Back',
  返回列表: 'Back to list',
  创建: 'Create',
  '创建中...': 'Creating...',
  编辑: 'Edit',
  删除: 'Delete',
  '删除中...': 'Deleting...',
  '检测中...': 'Testing...',
  确认删除: 'Confirm Delete',
  恢复: 'Restore',
  '恢复中...': 'Restoring...',
  测试: 'Test',
  切换在线: 'Go online',
  显示密码: 'Show password',
  隐藏密码: 'Hide password',

  // 媒体类型
  图片: 'Image',
  文件: 'File',
  大语言模型: 'LLM',
  嵌入模型: 'Embedding',
  重排序模型: 'ReRank',

  // 字段/占位
  名称: 'Name',
  颜色: 'Color',
  描述: 'Description',
  来源: 'Source',
  '你当前处于离线状态，回复只会处理此会话，不会接收新的转人工会话。':
    'You are offline. Replies only affect this conversation and will not make you receive new human handoffs.',
  条: 'items',

  // 筛选
  已添加标签: 'Added tag',
  已移除标签: 'Removed tag',
} as const;
