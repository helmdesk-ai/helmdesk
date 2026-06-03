<!--
  文件说明：后台应用布局片段，承接侧边栏、顶部状态和系统上下文。
-->
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarHeader,
  SidebarInset,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarProvider,
  SidebarTrigger,
} from '@/components/ui/sidebar';
import SidebarContextConsumer from '@/layouts/app/SidebarContextConsumer.vue';
import SidebarUserMenu from '@/layouts/app/SidebarUserMenu.vue';
import { cn, toUrl, urlIsActive } from '@/lib/utils';
import type { NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Pin } from '@lucide/vue';
import { computed } from 'vue';

export type SidebarShellNavItem = NavItem & {
  activeUrls?: string[];
};

interface Props {
  hideHeader?: boolean;
  headerHref: string;
  mainNavItems: SidebarShellNavItem[];
  footerNavItems: SidebarShellNavItem[];
  profileHref: string;
  profileLabel: string;
  logoutHref: string;
}

const props = withDefaults(defineProps<Props>(), {
  hideHeader: false,
});

const page = usePage();
const isOpen = Boolean(page.props.sidebarOpen);

const generalSettings = computed(() => page.props.generalSettings);
const systemName = computed(() => generalSettings.value.name);
const systemLogo = computed(() => generalSettings.value.logo_url);

const isExternalLink = (href: NavItem['href']) => {
  const url = toUrl(href);
  return url.startsWith('http://') || url.startsWith('https://');
};

const isSidebarNavItemActive = (item: SidebarShellNavItem) => {
  if (item.activeUrls && item.activeUrls.length > 0) {
    return item.activeUrls.some((u) =>
      urlIsActive(u, page.url, { mode: 'prefix' }),
    );
  }

  if (isExternalLink(item.href)) {
    return false;
  }

  return urlIsActive(item.href, page.url);
};
</script>

<template>
  <SidebarProvider :default-open="isOpen">
    <Sidebar collapsible="icon" variant="inset">
      <SidebarContextConsumer v-slot="{ toggleSidebar, state, isMobile }">
        <SidebarHeader class="group-data-[collapsible=icon]:p-0!">
          <div
            class="flex items-center justify-between group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:gap-2"
          >
            <SidebarMenu class="w-full group-data-[collapsible=icon]:p-2!">
              <SidebarMenuItem>
                <div
                  class="flex w-full items-center gap-2 px-0 py-0 group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:items-center"
                >
                  <Link
                    :href="props.headerHref"
                    class="shrink-0 p-2 group-data-[collapsible=icon]:p-0"
                  >
                    <div
                      class="flex aspect-square size-12 items-center justify-center rounded-md text-sidebar-primary-foreground"
                    >
                      <img
                        :src="systemLogo"
                        :alt="systemName + ' Logo'"
                        class="size-12 object-contain"
                      />
                    </div>
                  </Link>

                  <div
                    class="flex min-w-0 flex-1 items-center pr-2 group-data-[collapsible=icon]:hidden"
                  >
                    <span class="truncate text-sm leading-tight font-semibold">
                      {{ systemName }}
                    </span>
                  </div>

                  <slot name="collapsedHeaderAddon" />
                </div>
              </SidebarMenuItem>
            </SidebarMenu>

            <Button
              variant="ghost"
              size="icon"
              :class="
                cn(
                  'h-7 w-7 shrink-0 transition-colors duration-200',
                  'group-data-[collapsible=icon]:mr-0 group-data-[collapsible=icon]:mb-2',
                  'group-data-[state=expanded]/sidebar-wrapper:bg-sidebar-accent group-data-[state=expanded]/sidebar-wrapper:text-sidebar-accent-foreground',
                )
              "
              @click="toggleSidebar"
            >
              <Pin
                :class="
                  cn(
                    'h-4 w-4 transition-all duration-200',
                    'group-data-[state=collapsed]/sidebar-wrapper:rotate-45',
                  )
                "
                :fill="state.value === 'expanded' ? 'currentColor' : 'none'"
              />
              <span class="sr-only">Toggle Sidebar</span>
            </Button>
          </div>
        </SidebarHeader>

        <SidebarContent>
          <SidebarGroup class="px-2 py-0">
            <SidebarMenu>
              <SidebarMenuItem
                v-for="item in props.mainNavItems"
                :key="item.title"
              >
                <SidebarMenuButton
                  as-child
                  :is-active="isSidebarNavItemActive(item)"
                  :tooltip="item.title"
                >
                  <Link :href="toUrl(item.href)">
                    <component :is="item.icon" />
                    <span>{{ item.title }}</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
          <SidebarGroup class="group-data-[collapsible=icon]:p-0">
            <SidebarGroupContent>
              <SidebarMenu>
                <SidebarMenuItem
                  v-for="item in props.footerNavItems"
                  :key="item.title"
                >
                  <SidebarMenuButton
                    class="text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
                    as-child
                    :is-active="isSidebarNavItemActive(item)"
                  >
                    <a
                      v-if="isExternalLink(item.href)"
                      :href="toUrl(item.href)"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <component :is="item.icon" />
                      <span>{{ item.title }}</span>
                    </a>
                    <Link v-else :href="toUrl(item.href)">
                      <component :is="item.icon" />
                      <span>{{ item.title }}</span>
                    </Link>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>

          <SidebarMenu>
            <SidebarMenuItem>
              <slot
                name="userMenu"
                :isMobile="isMobile.value"
                :sidebarState="state.value"
              >
                <SidebarUserMenu
                  :profile-href="props.profileHref"
                  :profile-label="props.profileLabel"
                  :logout-href="props.logoutHref"
                  :is-mobile="isMobile.value"
                  :sidebar-state="state.value"
                />
              </slot>
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarFooter>
      </SidebarContextConsumer>
    </Sidebar>

    <SidebarInset class="overflow-x-hidden">
      <header
        v-if="!props.hideHeader"
        class="flex h-12 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-4 md:hidden"
      >
        <SidebarTrigger />
      </header>

      <slot />
    </SidebarInset>
  </SidebarProvider>
</template>
