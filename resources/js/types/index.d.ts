/**
 * 文件说明：前端共享类型声明，补充页面 props、全局对象和模块类型。
 */
import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import type {
  GeneralSettingsData,
  SystemUserContextData,
  UserNotificationPreferencesData,
} from './generated';

export interface Auth {
  user: User;
}

export interface NavItem {
  title: string;
  href: NonNullable<InertiaLinkProps['href']>;
  icon?: LucideIcon;
  isActive?: boolean;
}

export type AppPageProps<T extends object = Record<string, never>> = T & {
  name: string;
  quote: { message: string; author: string };
  auth: Auth;
  sidebarOpen: boolean;
  generalSettings: GeneralSettingsData;
  canAccessUsers?: boolean;
  canAccessContacts?: boolean;
  canAccessConversations?: boolean;
  canAccessTags?: boolean;
  canAccessAttributes?: boolean;
  canAccessCannedReplies?: boolean;
  canAccessKnowledgeBases?: boolean;
  canAccessReceptionPlans?: boolean;
  canAccessChannels?: boolean;
  canManageSystemSettings?: boolean;
  systemUserContext?: SystemUserContextData;
};

export interface User {
  id: string;
  name: string;
  email: string;
  avatar?: string;
  locale: string;
  timezone: string | null;
  notification_preferences: UserNotificationPreferencesData;
  is_super_admin?: boolean;
  email_verified_at: string | null;
  created_at: string;
  updated_at: string;
}
