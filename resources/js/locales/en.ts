/**
 * 文件说明：英文语言包，页面通过 useI18n 按中文 key 读取英文文案。
 */
import app from './en/app';
import auth from './en/auth';
import common from './en/common';
import contact from './en/contact';
import conversation from './en/conversation';
import settings from './en/settings';
import systemSettings from './en/system-settings';
import workspaceManagement from './en/workspace-management';
import workspaceSettings from './en/workspace-settings';

// 英文语言包 - 使用中文作为 key，值是对应的英文翻译（按模块拆分，便于维护）
export default {
  ...common,
  ...settings,
  ...auth,
  ...app,
  ...systemSettings,
  ...workspaceSettings,
  ...workspaceManagement,
  ...contact,
  ...conversation,
} as const;
