<!--
  文件说明：收件箱页面片段，承接收件箱列表、时间线和右侧上下文信息。
-->
<script setup lang="ts">
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import ConversationEventLine from '@/pages/contacts/ConversationEventLine.vue';
import ConversationMessageBubble from '@/pages/contacts/ConversationMessageBubble.vue';
import ConversationSummaryBlock from '@/pages/inbox/ConversationSummaryBlock.vue';
import type {
  ContactStitchedTimelineData,
  ContactTimelineEntryData,
  ConversationContactSummaryData,
  ConversationSummaryData,
  TagOptionData,
  TimelineEntryData,
} from '@/types/generated';
import { computed } from 'vue';

const props = defineProps<{
  timeline: ContactStitchedTimelineData;
  contactSummary?: ConversationContactSummaryData | null;
  currentConversationId?: string | null;
  currentUserId?: string | null;
  canRecallInCurrent?: boolean;
  translatingMessageIds?: ReadonlySet<string>;
  translatingSummaryIds?: ReadonlySet<string>;
  translationLocale?: string | null;
  currentUserLocale?: string | null;
  availableConversationTags?: TagOptionData[];
  showEvents?: boolean;
  highlightedMessageId?: string | null;
}>();

const emit = defineEmits<{
  (event: 'recall', conversationId: string, messageId: string): void;
  (event: 'reedit', content: string): void;
  (event: 'quote', entry: ContactTimelineEntryData): void;
}>();

function canRecallEntry(entry: ContactTimelineEntryData): boolean {
  if (!props.canRecallInCurrent) {
    return false;
  }

  if (entry.conversation_id !== props.currentConversationId) {
    return false;
  }

  if (entry.type !== 'message' || !entry.role || !props.currentUserId) {
    return false;
  }

  return (
    entry.role === 'teammate' && entry.actor_user_id === props.currentUserId
  );
}

function handleRecall(
  entry: ContactTimelineEntryData,
  messageId: string,
): void {
  emit('recall', entry.conversation_id, messageId);
}

const { t } = useI18n();
const { formatDateTime } = useDateTime();

const conversationIndexById = computed<Record<string, number>>(() => {
  const map: Record<string, number> = {};
  props.timeline.conversations.forEach((conversation, index) => {
    map[conversation.id] = index + 1;
  });
  return map;
});

const conversationById = computed<Record<string, ConversationSummaryData>>(
  () => {
    const map: Record<string, ConversationSummaryData> = {};
    props.timeline.conversations.forEach((conversation) => {
      map[conversation.id] = conversation;
    });
    return map;
  },
);

type StreamItem =
  | {
      kind: 'boundary';
      key: string;
      conversation: ConversationSummaryData;
      index: number;
    }
  | { kind: 'entry'; key: string; entry: ContactTimelineEntryData }
  | { kind: 'hidden_events'; key: string };

const entriesByConversationId = computed<
  Record<string, ContactTimelineEntryData[]>
>(() => {
  const map: Record<string, ContactTimelineEntryData[]> = {};

  for (const entry of props.timeline.entries) {
    map[entry.conversation_id] ??= [];
    map[entry.conversation_id].push(entry);
  }

  return map;
});

const stream = computed<StreamItem[]>(() => {
  const items: StreamItem[] = [];
  const conversationIds = Object.keys(entriesByConversationId.value);

  for (const conversationId of conversationIds) {
    const conversation = conversationById.value[conversationId];
    if (!conversation) {
      continue;
    }

    const entries = entriesByConversationId.value[conversationId];
    const visibleEntries =
      props.showEvents === false
        ? entries.filter((entry) => entry.type === 'message')
        : entries;

    items.push({
      kind: 'boundary',
      key: `boundary:${conversationId}`,
      conversation,
      index: conversationIndexById.value[conversationId] ?? 0,
    });

    for (const entry of visibleEntries) {
      items.push({ kind: 'entry', key: `entry:${entry.id}`, entry });
    }

    if (
      props.showEvents === false &&
      entries.length > 0 &&
      visibleEntries.length === 0
    ) {
      items.push({
        kind: 'hidden_events',
        key: `hidden-events:${conversationId}`,
      });
    }
  }

  return items;
});

function isMessage(entry: ContactTimelineEntryData): boolean {
  return entry.type === 'message';
}

function asTimelineEntry(entry: ContactTimelineEntryData): TimelineEntryData {
  return entry as unknown as TimelineEntryData;
}

function boundaryStatusLabel(conversation: ConversationSummaryData): string {
  if (conversation.status === 'closed') {
    return t('已关闭');
  }
  return t('进行中');
}

function shouldShowBoundarySummary(
  conversation: ConversationSummaryData,
): boolean {
  return (
    conversation.id !== props.currentConversationId &&
    Boolean(conversation.summary) &&
    conversation.message_count >= 6
  );
}

function streamItemSpacingClass(item: StreamItem, index: number): string {
  if (index === 0) {
    return '';
  }

  const previousItem = stream.value[index - 1];

  if (item.kind === 'boundary') {
    return 'mt-6';
  }

  if (item.kind === 'hidden_events') {
    return 'mt-1';
  }

  if (
    item.kind === 'entry' &&
    previousItem?.kind === 'entry' &&
    item.entry.type === 'event' &&
    previousItem.entry.type === 'event'
  ) {
    return 'mt-0.5';
  }

  return 'mt-3';
}
</script>

<template>
  <div
    v-if="stream.length === 0"
    class="py-6 text-center text-sm text-muted-foreground"
  >
    {{ t('暂无消息') }}
  </div>
  <div v-else class="flex flex-col">
    <div
      v-for="(item, index) in stream"
      :key="item.key"
      :class="streamItemSpacingClass(item, index)"
      :data-inbox-timeline-message-id="
        item.kind === 'entry' && isMessage(item.entry) ? item.entry.id : null
      "
    >
      <div v-if="item.kind === 'boundary'" class="space-y-2">
        <div
          class="flex items-center gap-3 text-xs text-muted-foreground"
          :class="{
            'font-semibold text-foreground':
              props.currentConversationId === item.conversation.id,
          }"
        >
          <span class="h-px flex-1 bg-border"></span>
          <span
            class="rounded-full border bg-background px-3 py-1"
            :class="{
              'border-primary text-primary':
                props.currentConversationId === item.conversation.id,
            }"
          >
            {{ t('第 {n} 次会话', { n: item.index }) }} ·
            {{
              formatDateTime(item.conversation.created_at, 'YYYY-MM-DD HH:mm')
            }}
            · {{ boundaryStatusLabel(item.conversation) }}
          </span>
          <span class="h-px flex-1 bg-border"></span>
        </div>
        <ConversationSummaryBlock
          v-if="shouldShowBoundarySummary(item.conversation)"
          class="mx-auto max-w-3xl"
          :data-inbox-conversation-summary-id="item.conversation.id"
          :conversation="item.conversation"
          :current-user-locale="
            props.currentUserLocale ?? props.translationLocale ?? ''
          "
          :available-tags="props.availableConversationTags"
          :is-translating="
            Boolean(props.translatingSummaryIds?.has(item.conversation.id))
          "
          variant="boundary"
        />
      </div>

      <ConversationMessageBubble
        v-else-if="item.kind === 'entry' && isMessage(item.entry)"
        class="rounded-md transition-colors"
        :class="{
          'bg-foreground/5 ring-1 ring-foreground/20':
            props.highlightedMessageId === item.entry.id,
        }"
        :entry="asTimelineEntry(item.entry)"
        :contact-summary="props.contactSummary ?? null"
        :can-recall="canRecallEntry(item.entry)"
        :is-translating="
          Boolean(props.translatingMessageIds?.has(item.entry.id))
        "
        :translation-locale="props.translationLocale ?? null"
        :can-quote="
          props.canRecallInCurrent &&
          item.entry.conversation_id === props.currentConversationId
        "
        @recall="(messageId) => handleRecall(item.entry, messageId)"
        @reedit="(content) => emit('reedit', content)"
        @quote="() => emit('quote', item.entry)"
      />
      <ConversationEventLine
        v-else-if="item.kind === 'entry'"
        :entry="asTimelineEntry(item.entry)"
      />
      <div v-else class="flex justify-center text-xs text-muted-foreground/70">
        {{ t('事件消息已隐藏') }}
      </div>
    </div>
  </div>
</template>
