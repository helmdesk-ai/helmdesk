<!--
  文件说明：知识库管理布局，左侧承接知识库 + 分组树侧边栏，右侧渲染主内容区。
-->
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet';
import { useI18n } from '@/composables/useI18n';
import { PanelLeft } from '@lucide/vue';

defineProps<{
  contentClass?: string;
}>();

const { t } = useI18n();
</script>

<template>
  <div class="flex flex-1 flex-col lg:flex-row">
    <!-- Desktop Sidebar -->
    <aside class="hidden w-full lg:block lg:w-68 lg:shrink-0 lg:self-stretch">
      <nav
        class="flex h-full flex-col border-r border-border/40 bg-card/50 shadow-sm backdrop-blur-sm"
      >
        <div class="space-y-0.5 px-4 pt-4 pb-3">
          <h2 class="text-base font-medium">
            {{ t('知识库') }}
          </h2>
          <p class="text-sm text-muted-foreground">
            {{ t('管理系统知识库和文档分组') }}
          </p>
        </div>

        <div class="flex min-h-0 flex-1 flex-col">
          <slot name="sidebar" />
        </div>
      </nav>
    </aside>

    <!-- Mobile Sidebar Sheet -->
    <div class="flex items-center gap-2 border-b px-4 py-2 lg:hidden">
      <Sheet>
        <SheetTrigger as-child>
          <Button variant="ghost" size="icon">
            <PanelLeft class="h-5 w-5" />
          </Button>
        </SheetTrigger>
        <SheetContent side="left" class="w-72 p-0">
          <SheetHeader class="space-y-0.5 border-b px-4 pt-4 pb-3 text-left">
            <SheetTitle class="text-xl font-semibold tracking-tight">
              {{ t('知识库') }}
            </SheetTitle>
            <SheetDescription>
              {{ t('管理系统知识库和文档分组') }}
            </SheetDescription>
          </SheetHeader>
          <div class="flex h-full flex-col overflow-hidden">
            <slot name="sidebar" />
          </div>
        </SheetContent>
      </Sheet>
      <span class="text-sm font-medium">{{ t('知识库') }}</span>
    </div>

    <Separator class="my-0 lg:hidden" />

    <!-- Main Content -->
    <div class="flex-1 px-4 py-6 sm:px-6">
      <section :class="['mx-auto w-full', contentClass ?? 'max-w-none']">
        <slot />
      </section>
    </div>
  </div>
</template>
