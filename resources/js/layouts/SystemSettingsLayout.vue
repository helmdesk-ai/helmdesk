<!--
  文件说明：系统设置区域的二级布局，承载基础、存储、邮件与供应商配置页面。
-->
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useI18n } from '@/composables/useI18n';
import { toUrl, urlIsActive } from '@/lib/utils';
import admin from '@/routes/admin';
import type { AppPageProps } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type ActiveMode = 'exact' | 'path' | 'prefix';

defineProps<{
  contentClass?: string;
}>();

const { t } = useI18n();
const page = usePage<AppPageProps>();

interface SubMenuItem {
  title: string;
  href: string;
  /**
   * 高亮匹配模式，默认 'prefix'：父级菜单在子路由（创建/编辑等）下保持高亮。
   */
  activeMode?: ActiveMode;
}

const sidebarNavItems = computed<SubMenuItem[]>(() => [
  {
    title: t('基础设置'),
    href: admin.general.show.url(),
  },
  {
    title: t('存储设置'),
    href: admin.storage.show.url(),
  },
  {
    title: t('邮箱服务器'),
    href: admin.mail.show.url(),
  },
  {
    title: t('AI 供应商'),
    href: admin.manage.ai.providers.index.url(),
  },
  {
    title: t('AI 模型管理'),
    href: admin.manage.ai.models.index.url(),
  },
  {
    title: t('知识库设置'),
    href: admin.knowledge.show.url(),
  },
  {
    title: t('MCP 服务'),
    href: admin.manage.mcp.servers.index.url(),
  },
  {
    title: t('翻译供应商'),
    href: admin.manage.translation.providers.index.url(),
  },
]);

const currentUrl = computed(() => page.url);

function isItemActive(href: string, mode: ActiveMode = 'prefix'): boolean {
  return urlIsActive(href, currentUrl.value, { mode });
}
</script>

<template>
  <div class="flex flex-1 flex-col lg:flex-row">
    <aside class="w-full lg:w-50 lg:self-stretch">
      <nav
        class="flex h-full flex-col space-y-3 border-r border-border/40 bg-card/50 px-6 pt-6 pb-4 shadow-sm backdrop-blur-sm"
      >
        <div class="space-y-0.5">
          <h2 class="text-base font-medium">
            {{ t('系统设置') }}
          </h2>
          <p class="text-sm text-muted-foreground">
            {{ t('管理系统基础配置、存储、邮件与供应商能力') }}
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
