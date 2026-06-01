<!--
  文件说明：后台应用布局片段，承接侧边栏、顶部状态和工作区上下文。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import ShowCreateWorkspacePageAction from '@/actions/App/Actions/Manage/ShowCreateWorkspacePageAction';
import Plan from '@/actions/App/Actions/Reception/Plan';
import AiAssistantWidget from '@/components/common/AiAssistantWidget.vue';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import { useWorkspaceNotificationAlerts } from '@/composables/useWorkspaceNotificationAlerts';
import SidebarShell, {
  type SidebarShellNavItem,
} from '@/layouts/app/SidebarShell.vue';
import SidebarUserMenuWithOnlineStatus from '@/layouts/app/SidebarUserMenuWithOnlineStatus.vue';
import admin from '@/routes/admin';
import logout from '@/routes/logout';
import { edit } from '@/routes/settings/profile';
import workspace from '@/routes/workspace';
import type { AppPageProps, NavItem } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import {
  AppWindow,
  BookOpen,
  Check,
  ChevronsUpDown,
  ClipboardList,
  GitBranch,
  Headphones,
  Inbox,
  LayoutGrid,
  MessagesSquare,
  Plus,
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

const currentWorkspace = useRequiredWorkspace();
const user = computed(() => page.props.auth.user);
const notificationPreferences = computed(
  () => page.props.auth.user.notification_preferences,
);
const workspaces = computed(() => {
  if (!Array.isArray(page.props.workspaces)) {
    throw new Error('workspaces is required for AppSidebarLayout.');
  }

  return page.props.workspaces;
});

const requireWorkspaceFlag = (
  value: boolean | undefined,
  name: string,
): boolean => {
  if (typeof value !== 'boolean') {
    throw new Error(`${name} is required for AppSidebarLayout.`);
  }

  return value;
};

type MainNavItem = SidebarShellNavItem;

const contactsBaseUrl = computed(() => {
  const sample = workspace.contacts.index.url({
    slug: currentWorkspace.value.slug,
    type: '__type__',
  });
  return sample.replace('/__type__/index', '');
});

const manageBaseUrl = computed(() => {
  // /w/{slug}/manage/workspaces/current -> /w/{slug}/manage
  return workspace.manage.workspaces.current.show
    .url(currentWorkspace.value.slug)
    .replace(/\/workspaces\/current$/, '');
});

const canAccessManageCenter = computed(() =>
  requireWorkspaceFlag(
    page.props.canAccessManageCenter,
    'canAccessManageCenter',
  ),
);
const canManageAi = computed(() =>
  requireWorkspaceFlag(page.props.canManageAi, 'canManageAi'),
);

const mainNavItems = computed<MainNavItem[]>(() => {
  const items: MainNavItem[] = [
    {
      title: t('仪表板'),
      href: workspace.dashboard.url(currentWorkspace.value.slug),
      icon: LayoutGrid,
      activeUrls: [workspace.dashboard.url(currentWorkspace.value.slug)],
    },
    {
      title: t('收件箱'),
      href: workspace.inbox.show.url(currentWorkspace.value.slug),
      icon: Inbox,
      activeUrls: [workspace.inbox.show.url(currentWorkspace.value.slug)],
    },
    {
      title: t('联系人'),
      href: workspace.contacts.index.url({
        slug: currentWorkspace.value.slug,
        type: 'all',
      }),
      icon: Users,
      activeUrls: [contactsBaseUrl.value],
    },
    {
      title: t('会话记录'),
      href: workspace.conversations.index.url(currentWorkspace.value.slug),
      icon: MessagesSquare,
      activeUrls: [
        workspace.conversations.index.url(currentWorkspace.value.slug),
      ],
    },
  ];

  if (canManageAi.value) {
    items.push({
      title: t('知识库'),
      href: KnowledgeBase.ListKnowledgeBasesAction.url(
        currentWorkspace.value.slug,
      ),
      icon: BookOpen,
      activeUrls: [`${manageBaseUrl.value}/knowledge-bases`],
    });
  }

  if (canAccessManageCenter.value) {
    items.push({
      title: t('客服'),
      href: workspace.manage.teammates.index.url(currentWorkspace.value.slug),
      icon: Headphones,
      activeUrls: [`${manageBaseUrl.value}/teammates`],
    });
  }

  if (canManageAi.value) {
    items.push({
      title: t('接待方案'),
      href: Plan.ShowReceptionPlanIndexPageAction.url(
        currentWorkspace.value.slug,
      ),
      icon: ClipboardList,
      activeUrls: [`${manageBaseUrl.value}/reception`],
    });
    items.push({
      title: t('渠道'),
      href: workspace.manage.channels.web.index.url(
        currentWorkspace.value.slug,
      ),
      icon: AppWindow,
      activeUrls: [`${manageBaseUrl.value}/channels`],
    });
  }

  if (canAccessManageCenter.value) {
    items.push({
      title: t('设置'),
      href: workspace.manage.workspaces.current.show.url(
        currentWorkspace.value.slug,
      ),
      icon: Settings,
      activeUrls: [
        `${manageBaseUrl.value}/workspaces`,
        workspace.cannedReplies.index.url(currentWorkspace.value.slug),
        `${manageBaseUrl.value}/tags`,
        `${manageBaseUrl.value}/attributes`,
        `${manageBaseUrl.value}/ai`,
        `${manageBaseUrl.value}/translation`,
        `${manageBaseUrl.value}/mcp-servers`,
      ],
    });
  }

  return items;
});

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
  ...(user.value?.is_super_admin
    ? ([
        {
          title: t('系统设置'),
          href: admin.general.show.url(),
          icon: Settings,
        },
      ] as NavItem[])
    : []),
]);

const logoutHref = computed(() => logout.web.url());

const switchWorkspace = (
  selectedWorkspace: (typeof workspaces.value)[number],
) => {
  if (selectedWorkspace.slug !== currentWorkspace.value.slug) {
    router.visit(workspace.dashboard.url(selectedWorkspace.slug), {
      preserveState: false,
      preserveScroll: false,
    });
  }
};

const goToCreateWorkspace = () => {
  router.visit(ShowCreateWorkspacePageAction.url(currentWorkspace.value.slug));
};

useWorkspaceNotificationAlerts({
  workspaceId: computed(() => currentWorkspace.value.id),
  workspaceSlug: computed(() => currentWorkspace.value.slug),
  userId: computed(() => user.value.id),
  preferences: notificationPreferences,
});
</script>

<template>
  <SidebarShell
    :hide-header="hideHeader"
    :header-href="workspace.dashboard.url(currentWorkspace.slug)"
    :header-subtitle="t('工作区')"
    :main-nav-items="mainNavItems"
    :footer-nav-items="footerNavItems"
    :profile-href="
      edit({ query: { from_workspace: currentWorkspace.slug } }).url
    "
    :profile-label="t('个人资料')"
    :logout-href="logoutHref"
  >
    <template #headerSubtitle>
      <DropdownMenu v-if="currentWorkspace">
        <DropdownMenuTrigger as-child>
          <button
            class="flex w-full items-center gap-1 rounded-md py-1 text-left text-xs transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
          >
            <div
              class="flex h-5 w-5 shrink-0 items-center justify-center overflow-hidden rounded text-sidebar-primary-foreground"
            >
              <img
                :src="currentWorkspace.logo_url"
                :alt="currentWorkspace.name"
                class="h-full w-full object-cover"
              />
            </div>
            <span class="flex-1 truncate text-xs font-medium">
              {{ currentWorkspace.name }}
            </span>
            <ChevronsUpDown class="h-3 w-3 shrink-0 opacity-50" />
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-64">
          <div class="px-2 py-1.5 text-xs font-semibold text-muted-foreground">
            {{ t('切换工作区') }}
          </div>
          <DropdownMenuItem
            v-for="w in workspaces"
            :key="w.id"
            class="flex cursor-pointer items-center gap-2"
            @click="switchWorkspace(w)"
          >
            <div
              class="flex h-6 w-6 shrink-0 items-center justify-center overflow-hidden rounded-md text-sidebar-primary-foreground"
            >
              <img
                :src="w.logo_url"
                :alt="w.name"
                class="h-full w-full object-cover"
              />
            </div>
            <span class="flex-1 truncate">{{ w.name }}</span>
            <Check
              v-if="w.slug === currentWorkspace?.slug"
              class="h-4 w-4 shrink-0"
            />
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem
            class="flex cursor-pointer items-center gap-2"
            @click="goToCreateWorkspace"
          >
            <div
              class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-dashed"
            >
              <Plus class="h-4 w-4" />
            </div>
            <span>{{ t('添加工作区') }}</span>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </template>

    <template #collapsedHeaderAddon>
      <div class="hidden group-data-[collapsible=icon]:block">
        <div
          class="flex h-6 w-6 items-center justify-center overflow-hidden rounded text-sidebar-primary-foreground"
        >
          <img
            :src="currentWorkspace.logo_url"
            :alt="currentWorkspace.name"
            class="h-full w-full object-cover"
          />
        </div>
      </div>
    </template>

    <template #userMenu="{ isMobile, sidebarState }">
      <SidebarUserMenuWithOnlineStatus
        :profile-href="
          edit({ query: { from_workspace: currentWorkspace.slug } }).url
        "
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
