/**
 * 文件说明：中文语言包，维护前端页面默认展示文案。
 */
import app from './zh-CN/app';
import auth from './zh-CN/auth';
import common from './zh-CN/common';
import contact from './zh-CN/contact';
import conversation from './zh-CN/conversation';
import settings from './zh-CN/settings';
import systemAdmin from './zh-CN/system-admin';
import systemManagement from './zh-CN/system-management';
import systemSettings from './zh-CN/system-settings';

export default {
  ...common,
  ...settings,
  ...auth,
  ...app,
  ...systemSettings,
  ...systemManagement,
  ...systemAdmin,
  ...contact,
  ...conversation,
} as const;
