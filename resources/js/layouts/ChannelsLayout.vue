<!--
  文件说明：渠道区域的二级布局，承载网站、Telegram 等访客接入渠道的子页面。
-->
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useI18n } from '@/composables/useI18n';
import { toUrl, urlIsActive } from '@/lib/utils';
import admin from '@/routes/admin';
import type { AppPageProps } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Globe, Send } from '@lucide/vue';
import { computed, type Component } from 'vue';

type ActiveMode = 'exact' | 'path' | 'prefix';

defineProps<{
  contentClass?: string;
}>();

const { t } = useI18n();
const page = usePage<AppPageProps>();

interface SubMenuItem {
  title: string;
  href: string;
  icon?: Component;
  /**
   * 高亮匹配模式，默认 'prefix'：父级菜单在子路由（详情、编辑等）下保持高亮。
   */
  activeMode?: ActiveMode;
}

const sidebarNavItems = computed<SubMenuItem[]>(() => {
  return [
    {
      title: t('网站'),
      href: admin.manage.channels.web.index.url(),
      icon: Globe,
    },
    {
      title: t('Telegram'),
      href: admin.manage.channels.telegram.index.url(),
      icon: Send,
    },
  ];
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
        class="flex h-full flex-col space-y-3 border-r border-border/40 bg-card/50 px-6 pt-6 pb-4 shadow-sm backdrop-blur-sm"
      >
        <div class="space-y-0.5">
          <h2 class="text-base font-medium">
            {{ t('渠道管理') }}
          </h2>
          <p class="text-sm text-muted-foreground">
            {{ t('管理系统访客接入渠道') }}
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
              <component :is="item.icon" v-if="item.icon" class="h-4 w-4" />
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
