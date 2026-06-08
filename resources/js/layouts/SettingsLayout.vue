<!--
  文件说明：前端页面布局，连接页面内容与后台应用外壳。
-->
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useI18n } from '@/composables/useI18n';
import { toUrl, urlIsActive } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/settings/appearance';
import { edit as editLanguage } from '@/routes/settings/language';
import { edit as editNotifications } from '@/routes/settings/notifications';
import { edit as editPassword } from '@/routes/settings/password';
import { edit as editProfile } from '@/routes/settings/profile';
import { show } from '@/routes/settings/two-factor';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const { t } = useI18n();

const sidebarNavItems = computed<NavItem[]>(() => {
  return [
    {
      title: t('个人资料'),
      href: editProfile(),
    },
    {
      title: t('密码'),
      href: editPassword(),
    },
    {
      title: t('两步验证'),
      href: show(),
    },
    {
      title: t('语言和时区'),
      href: editLanguage(),
    },
    {
      title: t('通知'),
      href: editNotifications(),
    },
    {
      title: t('外观'),
      href: editAppearance(),
    },
  ];
});

const currentPath =
  typeof window !== 'undefined' ? window.location.pathname : '';
</script>

<template>
  <div class="flex flex-1 flex-col lg:flex-row">
    <aside class="w-full lg:w-50 lg:self-stretch">
      <nav
        class="flex h-full flex-col space-y-3 border-r border-border/40 bg-card/50 px-4 pt-6 pb-4 shadow-sm backdrop-blur-sm"
      >
        <div class="space-y-0.5">
          <h2 class="text-base font-medium">
            {{ t('设置') }}
          </h2>
          <p class="text-sm text-muted-foreground">
            {{ t('管理你的个人资料和账户设置') }}
          </p>
        </div>

        <div class="flex flex-col space-y-1">
          <Button
            v-for="item in sidebarNavItems"
            :key="toUrl(item.href)"
            variant="ghost"
            :class="[
              'w-full justify-start',
              {
                'bg-muted': urlIsActive(item.href, currentPath, {
                  mode: 'path',
                }),
              },
            ]"
            as-child
          >
            <Link :href="typeof item.href === 'string' ? item.href : item.href">
              <component :is="item.icon" class="h-4 w-4" />
              {{ item.title }}
            </Link>
          </Button>
        </div>
      </nav>
    </aside>

    <Separator class="my-6 lg:hidden" />

    <div class="flex-1 px-4 py-6 sm:px-6">
      <section class="mx-auto w-full max-w-none space-y-12">
        <slot />
      </section>
    </div>
  </div>
</template>
