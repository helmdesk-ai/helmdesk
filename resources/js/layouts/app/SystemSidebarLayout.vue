<!--
  文件说明：单租户总管理后台布局片段，承接统一侧边栏、顶部状态和系统上下文。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import Plan from '@/actions/App/Actions/Reception/Plan';
import AiAssistantWidget from '@/components/common/AiAssistantWidget.vue';
import { useI18n } from '@/composables/useI18n';
import { useSystemNotificationAlerts } from '@/composables/useSystemNotificationAlerts';
import SidebarShell, {
  type SidebarShellNavItem,
} from '@/layouts/app/SidebarShell.vue';
import SidebarUserMenuWithOnlineStatus from '@/layouts/app/SidebarUserMenuWithOnlineStatus.vue';
import admin from '@/routes/admin';
import logout from '@/routes/logout';
import { edit } from '@/routes/settings/profile';
import type { AppPageProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import {
  BookOpen,
  ClipboardList,
  GitBranch,
  Globe,
  Inbox,
  LayoutGrid,
  MessagesSquare,
  Settings,
  Users,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
  hideHeader?: boolean;
}

withDefaults(defineProps<Props>(), {
  hideHeader: false,
});

const { t } = useI18n();
const page = usePage<AppPageProps>();

const user = computed(() => page.props.auth.user);
const notificationPreferences = computed(
  () => page.props.auth.user.notification_preferences,
);

const routePath = (url: string) => {
  const origin =
    typeof window !== 'undefined' ? window.location.origin : 'http://localhost';

  return new URL(url, origin).pathname;
};

const contactsBaseUrl = computed(() => {
  const sample = routePath(admin.contacts.index.url({ type: '__type__' }));
  return sample.replace('/__type__/index', '');
});

const manageBaseUrl = computed(() => {
  return routePath(admin.manage.tags.index.url()).replace(/\/tags$/, '');
});

const mainNavItems = computed<SidebarShellNavItem[]>(() => [
  {
    title: t('仪表板'),
    href: admin.dashboard.url(),
    icon: LayoutGrid,
    activeUrls: [routePath(admin.dashboard.url())],
  },
  {
    title: t('收件箱'),
    href: admin.inbox.show.url(),
    icon: Inbox,
    activeUrls: [routePath(admin.inbox.show.url())],
  },
  {
    title: t('联系人'),
    href: admin.contacts.index.url({ type: 'all' }),
    icon: Users,
    activeUrls: [contactsBaseUrl.value],
  },
  {
    title: t('会话记录'),
    href: admin.conversations.index.url(),
    icon: MessagesSquare,
    activeUrls: [routePath(admin.conversations.index.url())],
  },
  {
    title: t('知识库'),
    href: KnowledgeBase.ListKnowledgeBasesAction.url(),
    icon: BookOpen,
    activeUrls: [`${manageBaseUrl.value}/knowledge-bases`],
  },
  {
    title: t('接待方案'),
    href: Plan.ShowReceptionPlanIndexPageAction.url(),
    icon: ClipboardList,
    activeUrls: [`${manageBaseUrl.value}/reception`],
  },
  {
    title: t('渠道管理'),
    href: admin.manage.channels.web.index.url(),
    icon: Globe,
    activeUrls: [`${manageBaseUrl.value}/channels`],
  },
]);

const footerNavItems = computed<SidebarShellNavItem[]>(() => [
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
  {
    title: t('系统设置'),
    href: admin.general.show.url(),
    icon: Settings,
    activeUrls: [
      routePath(admin.general.show.url()),
      routePath(admin.storage.show.url()),
      routePath(admin.mail.show.url()),
      `${manageBaseUrl.value}/tags`,
      `${manageBaseUrl.value}/attributes`,
      routePath(admin.cannedReplies.index.url()),
      `${manageBaseUrl.value}/ai`,
      `${manageBaseUrl.value}/mcp-servers`,
      `${manageBaseUrl.value}/translation`,
    ],
  },
]);

const profileHref = computed(() => edit().url);
const logoutHref = computed(() => logout.admin.url());

useSystemNotificationAlerts({
  userId: computed(() => user.value.id),
  preferences: notificationPreferences,
});
</script>

<template>
  <SidebarShell
    :hide-header="hideHeader"
    :header-href="admin.dashboard.url()"
    :header-subtitle="t('总管理后台')"
    :main-nav-items="mainNavItems"
    :footer-nav-items="footerNavItems"
    :profile-href="profileHref"
    :profile-label="t('个人资料')"
    :logout-href="logoutHref"
  >
    <template #userMenu="{ isMobile, sidebarState }">
      <SidebarUserMenuWithOnlineStatus
        :profile-href="profileHref"
        :profile-label="t('个人资料')"
        :logout-href="logoutHref"
        :is-mobile="isMobile"
        :sidebar-state="sidebarState"
      />
    </template>

    <slot />

    <AiAssistantWidget />
  </SidebarShell>
</template>
