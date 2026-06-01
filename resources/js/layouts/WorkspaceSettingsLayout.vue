<!--
  文件说明：工作区"设置"区域的二级布局，承载常规设置、大模型供应商等子页面。
-->
<script setup lang="ts">
import AiProvider from '@/actions/App/Actions/AiProvider';
import Mcp from '@/actions/App/Actions/Mcp';
import Translation from '@/actions/App/Actions/Translation';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import { toUrl, urlIsActive } from '@/lib/utils';
import workspaceRoutes from '@/routes/workspace';
import type { AppPageProps } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type ActiveMode = 'exact' | 'path' | 'prefix';

defineProps<{
  contentClass?: string;
}>();

const { t } = useI18n();
const page = usePage<AppPageProps>();
const currentWorkspace = useRequiredWorkspace();
const requireWorkspaceFlag = (
  value: boolean | undefined,
  name: string,
): boolean => {
  if (typeof value !== 'boolean') {
    throw new Error(`${name} is required for WorkspaceSettingsLayout.`);
  }

  return value;
};

const canAccessManageCenter = computed(() =>
  requireWorkspaceFlag(
    page.props.canAccessManageCenter,
    'canAccessManageCenter',
  ),
);
const canManageAi = computed(() =>
  requireWorkspaceFlag(page.props.canManageAi, 'canManageAi'),
);

interface SubMenuItem {
  title: string;
  href: string;
  /**
   * 高亮匹配模式，默认 'prefix'：父级菜单在子路由（创建/编辑/详情等）下保持高亮。
   */
  activeMode?: ActiveMode;
}

const sidebarNavItems = computed<SubMenuItem[]>(() => {
  const items: SubMenuItem[] = [
    {
      title: t('常规设置'),
      href: workspaceRoutes.manage.workspaces.current.show.url(
        currentWorkspace.value.slug,
      ),
    },
  ];

  if (canAccessManageCenter.value) {
    items.push(
      {
        title: t('标签'),
        href: workspaceRoutes.manage.tags.index.url(currentWorkspace.value.slug),
      },
      {
        title: t('自定义属性'),
        href: workspaceRoutes.manage.attributes.index.url(
          currentWorkspace.value.slug,
        ),
      },
    );
  }

  // 快捷回复对所有成员开放：管理员维护工作区共享、普通成员维护自己的个人模版。
  items.push({
    title: t('快捷回复'),
    href: workspaceRoutes.cannedReplies.index.url(currentWorkspace.value.slug),
  });

  if (canManageAi.value) {
    items.push(
      {
        title: t('大模型供应商'),
        href: AiProvider.ShowWorkspaceAiProvidersAction.url(
          currentWorkspace.value.slug,
        ),
      },
      {
        title: t('翻译供应商'),
        href: Translation.ShowWorkspaceTranslationProvidersAction.url(
          currentWorkspace.value.slug,
        ),
      },
      {
        title: t('MCP 服务'),
        href: Mcp.ShowWorkspaceMcpServersAction.url(
          currentWorkspace.value.slug,
        ),
      },
    );
  }

  return items;
});

const currentUrl = computed(() => page.url);

function isItemActive(href: string, mode: ActiveMode = 'prefix'): boolean {
  return urlIsActive(href, currentUrl.value, { mode });
}
</script>

<template>
  <div class="flex flex-1 flex-col lg:flex-row">
    <aside class="w-full lg:w-50 lg:self-stretch">
      <nav
        class="flex h-full flex-col space-y-3 border-r border-border/40 bg-card/50 p-4 shadow-sm backdrop-blur-sm"
      >
        <div class="space-y-0.5">
          <h2 class="text-xl font-semibold tracking-tight">
            {{ t('设置') }}
          </h2>
          <p class="text-sm text-muted-foreground">
            {{ t('管理工作区设置、数据能力与 AI 配置') }}
          </p>
        </div>

        <div class="flex flex-col space-y-1">
          <Button
            v-for="item in sidebarNavItems"
            :key="toUrl(item.href)"
            variant="ghost"
            :class="[
              'w-full justify-start text-sm font-normal',
              { 'bg-muted': isItemActive(item.href, item.activeMode) },
            ]"
            as-child
          >
            <Link :href="item.href">
              {{ item.title }}
            </Link>
          </Button>
        </div>
      </nav>
    </aside>

    <Separator class="my-6 lg:hidden" />

    <div class="flex-1 px-4 py-6 sm:px-6">
      <section
        :class="['mx-auto w-full space-y-12', contentClass ?? 'max-w-none']"
      >
        <slot />
      </section>
    </div>
  </div>
</template>
