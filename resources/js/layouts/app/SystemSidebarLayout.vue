<!--
  文件说明：后台应用布局片段，承接侧边栏、顶部状态和工作区上下文。
-->
<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import SidebarShell, {
  type SidebarShellNavItem,
} from '@/layouts/app/SidebarShell.vue';
import admin from '@/routes/admin';
import logout from '@/routes/logout';
import { edit as editProfile } from '@/routes/settings/profile';
import type { NavItem } from '@/types';
import {
  BookOpen,
  Building2,
  Database,
  GitBranch,
  Mail,
  Settings,
  Users,
} from 'lucide-vue-next';
import { computed } from 'vue';

const { t } = useI18n();

const routePath = (url: string) => {
  const origin =
    typeof window !== 'undefined' ? window.location.origin : 'http://localhost';

  return new URL(url, origin).pathname;
};

const mainNavItems = computed<SidebarShellNavItem[]>(() => [
  {
    title: t('基础设置'),
    href: admin.general.show.url(),
    icon: Settings,
    activeUrls: [routePath(admin.general.show.url())],
  },
  {
    title: t('用户管理'),
    href: admin.users.index.url(),
    icon: Users,
    activeUrls: [routePath(admin.users.index.url())],
  },
  {
    title: t('工作区管理'),
    href: admin.workspaces.index.url(),
    icon: Building2,
    activeUrls: [routePath(admin.workspaces.index.url())],
  },
  {
    title: t('存储设置'),
    href: admin.storage.show.url(),
    icon: Database,
    activeUrls: [routePath(admin.storage.show.url())],
  },
  {
    title: t('邮箱服务器'),
    href: admin.mail.show.url(),
    icon: Mail,
    activeUrls: [routePath(admin.mail.show.url())],
  },
]);

const footerNavItems = computed<NavItem[]>(() => [
  {
    title: t('GitHub仓库'),
    href: 'https://github.com/shellphy/helmdesk',
    icon: GitBranch,
  },
  {
    title: t('文档'),
    href: 'https://docs.helmdesk.app',
    icon: BookOpen,
  },
]);

const profileHref = computed(() => editProfile.url());
const logoutHref = computed(() => logout.admin.url());
</script>

<template>
  <SidebarShell
    :header-href="admin.general.show.url()"
    :header-subtitle="t('系统管理')"
    :main-nav-items="mainNavItems"
    :footer-nav-items="footerNavItems"
    :profile-href="profileHref"
    :profile-label="t('个人设置')"
    :logout-href="logoutHref"
  >
    <slot />
  </SidebarShell>
</template>
