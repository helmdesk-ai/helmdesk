<!--
  收件箱聊天记录搜索结果列表，替换时间线区域展示匹配消息。
-->
<script setup lang="ts">
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import type {
  ConversationSummaryData,
  InboxMessageSearchResultData,
} from '@/types/generated';
import { computed } from 'vue';

const props = defineProps<{
  results: InboxMessageSearchResultData[];
  conversations: ConversationSummaryData[];
  search: string;
  loading: boolean;
}>();

const emit = defineEmits<{
  (event: 'select', result: InboxMessageSearchResultData): void;
}>();

const { t } = useI18n();
const { formatDateTime } = useDateTime();

const cjkSegmentPattern =
  /([\u2e80-\u9fff\uf900-\ufaff\ufe30-\ufe4f\u{20000}-\u{2fa1f}]+)/gu;
const cjkOnlyPattern =
  /^[\u2e80-\u9fff\uf900-\ufaff\ufe30-\ufe4f\u{20000}-\u{2fa1f}]+$/u;
const nonCjkTokenPattern = /[\p{L}\p{N}_@-]+/gu;

const conversationIndexById = computed<Record<string, number>>(() => {
  const map: Record<string, number> = {};
  props.conversations.forEach((c, i) => {
    map[c.id] = i + 1;
  });
  return map;
});

function escapeHtml(content: string): string {
  return content
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function escapeRegExp(content: string): string {
  return content.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function highlightTokens(search: string): string[] {
  const tokens: string[] = [];
  const segments = search
    .trim()
    .toLowerCase()
    .split(cjkSegmentPattern)
    .filter(Boolean);

  for (const segment of segments) {
    if (cjkOnlyPattern.test(segment)) {
      tokens.push(...Array.from(segment));
      continue;
    }

    tokens.push(...(segment.match(nonCjkTokenPattern) ?? []));
  }

  return Array.from(new Set(tokens)).sort((a, b) => b.length - a.length);
}

function highlightContent(content: string | null): string {
  if (!content) return '';

  const safeContent = escapeHtml(content);
  if (!props.search) return safeContent;

  const tokens = highlightTokens(props.search);

  if (tokens.length === 0) {
    return safeContent;
  }

  const regex = new RegExp(`(${tokens.map(escapeRegExp).join('|')})`, 'giu');

  return safeContent.replace(
    regex,
    '<mark class="bg-foreground/15 text-foreground rounded-sm px-0.5">$1</mark>',
  );
}
</script>

<template>
  <div v-if="loading" class="py-8 text-center text-sm text-muted-foreground">
    {{ t('搜索中...') }}
  </div>
  <div
    v-else-if="results.length === 0"
    class="py-8 text-center text-sm text-muted-foreground"
  >
    {{ t('未找到匹配的消息') }}
  </div>
  <div v-else class="flex flex-col divide-y">
    <button
      v-for="result in results"
      :key="result.id"
      type="button"
      class="w-full px-2 py-3 text-left transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
      @click="emit('select', result)"
    >
      <div class="mb-1 flex items-center gap-2 text-xs text-muted-foreground">
        <span
          v-if="conversationIndexById[result.conversation_id]"
          class="rounded-full border bg-background px-2 py-0.5"
        >
          {{
            t('第 {n} 次会话', {
              n: conversationIndexById[result.conversation_id],
            })
          }}
        </span>
        <span v-if="result.role_label" class="font-medium">
          {{ result.role_label }}
        </span>
        <span v-if="result.sender_name">{{ result.sender_name }}</span>
        <span class="ml-auto shrink-0">
          {{ formatDateTime(result.occurred_at, 'MM-DD HH:mm') }}
        </span>
      </div>
      <!-- eslint-disable-next-line vue/no-v-html -->
      <div
        class="line-clamp-3 text-sm leading-relaxed text-foreground"
        v-html="highlightContent(result.matched_content)"
      />
    </button>
  </div>
</template>
