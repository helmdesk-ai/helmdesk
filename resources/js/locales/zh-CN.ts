/**
 * 文件说明：中文语言包，维护前端页面默认展示文案。
 */
import app from './zh-CN/app';
import auth from './zh-CN/auth';
import common from './zh-CN/common';
import contact from './zh-CN/contact';
import conversation from './zh-CN/conversation';
import settings from './zh-CN/settings';
import systemSettings from './zh-CN/system-settings';
import workspaceManagement from './zh-CN/workspace-management';
import workspaceSettings from './zh-CN/workspace-settings';

export default {
  ...common,
  ...settings,
  ...auth,
  ...app,
  ...systemSettings,
  ...workspaceManagement,
  ...workspaceSettings,
  ...contact,
  ...conversation,
} as const;
