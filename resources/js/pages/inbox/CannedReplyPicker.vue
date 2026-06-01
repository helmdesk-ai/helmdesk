<!--
  文件说明：收件箱回复 composer 的"快捷回复"选择器。
  - 触发方式：工具栏上的按钮，或 textarea 中输入 `/keyword`（由父组件检测后调用 `open(query)`）
  - 上下键浏览候选；Enter / 点击选中
  - 选中后调用 use-and-render，把渲染后的内容回传给父组件，由父组件插入 textarea
-->
<script setup lang="ts">
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import cannedReplyRoutes from '@/routes/workspace/canned-replies';
import type { CannedReplyComposerItemData } from '@/types/generated';
import axios from 'axios';
import { Sparkles } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

interface RenderedReply {
  id: string;
  rendered_content: string;
  warnings: string[];
}

const props = defineProps<{
  open: boolean;
  conversationId: string | null;
  query: string;
}>();

const emit = defineEmits<{
  'update:open': [value: boolean];
  rendered: [payload: RenderedReply];
  error: [message: string];
}>();

const { t } = useI18n();
const currentWorkspace = useRequiredWorkspace();

const items = ref<CannedReplyComposerItemData[]>([]);
const activeIndex = ref(0);
const loading = ref(false);
const lastRequestToken = ref(0);
const usingId = ref<string | null>(null);

const visibleItems = computed(() => items.value);

const open = computed({
  get: () => props.open,
  set: (value: boolean) => emit('update:open', value),
});

const search = async (rawQuery: string) => {
  const requestToken = ++lastRequestToken.value;
  loading.value = true;

  try {
    const response = await axios.get<{ items: CannedReplyComposerItemData[] }>(
      cannedReplyRoutes.search.url(currentWorkspace.value.slug),
      {
        params: {
          q: rawQuery,
          conversation_id: props.conversationId ?? undefined,
          limit: 10,
        },
      },
    );

    if (requestToken !== lastRequestToken.value) {
      return;
    }

    items.value = response.data.items ?? [];
    activeIndex.value = 0;
  } catch (error: unknown) {
    if (requestToken === lastRequestToken.value) {
      items.value = [];
      const message =
        (error as { response?: { data?: { message?: string } } })?.response
          ?.data?.message ?? t('加载快捷回复失败');
      emit('error', message);
    }
  } finally {
    if (requestToken === lastRequestToken.value) {
      loading.value = false;
    }
  }
};

const useReply = async (reply: CannedReplyComposerItemData) => {
  if (usingId.value !== null) {
    return;
  }
  usingId.value = reply.id;

  try {
    const response = await axios.post<RenderedReply>(
      cannedReplyRoutes.useAndRender.url({
        slug: currentWorkspace.value.slug,
        cannedReply: reply.id,
      }),
      {
        conversation_id: props.conversationId ?? null,
      },
    );

    emit('rendered', response.data);
    emit('update:open', false);
  } catch (error: unknown) {
    const message =
      (error as { response?: { data?: { message?: string } } })?.response?.data
        ?.message ?? t('使用快捷回复失败');
    emit('error', message);
  } finally {
    usingId.value = null;
  }
};

const moveActive = (delta: number) => {
  if (visibleItems.value.length === 0) {
    return;
  }
  const next = activeIndex.value + delta;
  const total = visibleItems.value.length;
  activeIndex.value = ((next % total) + total) % total;
};

const handleKeydown = (event: KeyboardEvent) => {
  if (!props.open) {
    return;
  }

  if (event.key === 'ArrowDown') {
    event.preventDefault();
    moveActive(1);
    return;
  }

  if (event.key === 'ArrowUp') {
    event.preventDefault();
    moveActive(-1);
    return;
  }

  if (event.key === 'Enter' && !event.isComposing) {
    if (visibleItems.value[activeIndex.value]) {
      event.preventDefault();
      useReply(visibleItems.value[activeIndex.value]);
    }
    return;
  }

  if (event.key === 'Escape') {
    event.preventDefault();
    emit('update:open', false);
  }
};

defineExpose({ handleKeydown });

watch(
  () => [props.open, props.query] as const,
  async ([nextOpen, nextQuery]) => {
    if (!nextOpen) {
      items.value = [];
      activeIndex.value = 0;
      return;
    }

    await search(nextQuery);
    await nextTick();
  },
  { immediate: true },
);

const truncate = (text: string, max = 90) => {
  if (text.length <= max) {
    return text;
  }
  return `${text.slice(0, max)}…`;
};
</script>

<template>
  <Popover :open="open" @update:open="emit('update:open', $event)">
    <PopoverTrigger as-child>
      <slot name="trigger" />
    </PopoverTrigger>
    <PopoverContent
      class="w-[28rem] p-0"
      align="start"
      side="top"
      :side-offset="8"
      @open-auto-focus="(event) => event.preventDefault()"
      @close-auto-focus="(event) => event.preventDefault()"
    >
      <div class="border-b px-3 py-2 text-xs text-muted-foreground">
        {{
          query ? t('搜索"{query}"的快捷回复', { query }) : t('选择快捷回复')
        }}
      </div>
      <div class="max-h-72 overflow-y-auto">
        <div
          v-if="loading && visibleItems.length === 0"
          class="px-3 py-6 text-center text-xs text-muted-foreground"
        >
          {{ t('加载中…') }}
        </div>
        <div
          v-else-if="visibleItems.length === 0"
          class="px-3 py-6 text-center text-xs text-muted-foreground"
        >
          {{ query ? t('暂无匹配的快捷回复') : t('暂无快捷回复') }}
        </div>
        <button
          v-for="(item, index) in visibleItems"
          :key="item.id"
          type="button"
          class="flex w-full flex-col gap-1 px-3 py-2 text-left transition-colors hover:bg-muted"
          :class="{ 'bg-muted': index === activeIndex }"
          @mouseenter="activeIndex = index"
          @click="useReply(item)"
        >
          <div class="flex items-center gap-2">
            <span class="font-medium">{{ item.name }}</span>
            <span
              v-if="item.shortcut"
              class="rounded bg-background px-1 font-mono text-[11px] text-muted-foreground"
            >
              /{{ item.shortcut }}
            </span>
            <span
              v-if="item.is_personal"
              class="rounded bg-muted px-1 text-[11px] text-muted-foreground"
            >
              {{ t('仅自己') }}
            </span>
            <span
              v-else
              class="rounded bg-muted px-1 text-[11px] text-muted-foreground"
            >
              {{ t('工作区共享') }}
            </span>
            <Sparkles
              v-if="item.relevance_score > 0"
              class="ml-auto h-3 w-3 text-primary"
            />
          </div>
          <span class="line-clamp-2 text-xs text-muted-foreground">
            {{ truncate(item.content, 140) }}
          </span>
        </button>
      </div>
      <div
        class="flex items-center justify-between border-t px-3 py-1.5 text-[11px] text-muted-foreground"
      >
        <span>{{ t('↑↓ 选择，Enter 确认') }}</span>
        <span v-if="usingId" class="text-primary">
          {{ t('插入中…') }}
        </span>
      </div>
    </PopoverContent>
  </Popover>
</template>
