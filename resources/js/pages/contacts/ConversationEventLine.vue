<!--
  会话时间线事件行组件。
  渲染后端下发的 event_display 数据，作为右侧低干扰活动消息展示。
-->
<script setup lang="ts">
import type { TimelineEntryData } from '@/types/generated';
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
  entry: TimelineEntryData;
}>();

const display = computed(() => {
  if (!props.entry.event_display) {
    throw new Error(`Missing event display for ${props.entry.subtype}`);
  }

  return props.entry.event_display;
});

const hasFacts = computed(() => display.value.facts.length > 0);
const hasDetail = computed(() => Boolean(display.value.detail));
const hasExpandableContent = computed(() => hasDetail.value || hasFacts.value);
const showExpandedContent = computed(
  () => expanded.value && hasExpandableContent.value,
);
const expanded = ref(false);

const toneClass = computed<string>(() => {
  switch (display.value.tone) {
    case 'warning':
      return 'bg-muted/70 text-foreground/88 ring-1 ring-foreground/10 hover:bg-muted hover:text-foreground';
    case 'important':
      return 'bg-muted/60 text-foreground/86 ring-1 ring-foreground/10 hover:bg-muted/80 hover:text-foreground';
    case 'muted':
      return 'bg-muted/40 text-foreground/80 hover:bg-muted/55 hover:text-foreground/90';
    case 'normal':
    default:
      return 'bg-muted/45 text-foreground/84 hover:bg-muted/60 hover:text-foreground/92';
  }
});

function toggleExpanded(): void {
  if (!hasExpandableContent.value) {
    return;
  }

  const selection = window.getSelection();
  if (selection && !selection.isCollapsed) {
    return;
  }

  expanded.value = !expanded.value;
}

function handleToggleKeydown(event: KeyboardEvent): void {
  if (!hasExpandableContent.value) {
    return;
  }

  event.preventDefault();
  toggleExpanded();
}
</script>

<template>
  <div class="flex justify-end">
    <div
      :role="hasExpandableContent ? 'button' : undefined"
      :tabindex="hasExpandableContent ? 0 : undefined"
      :class="[
        'max-w-[72%] min-w-0 rounded-md px-2.5 py-0.5 text-right text-[11px] leading-4 font-normal italic shadow-xs transition focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none',
        hasExpandableContent ? 'cursor-pointer' : 'cursor-default',
        toneClass,
      ]"
      :aria-expanded="hasExpandableContent ? expanded : undefined"
      @click="toggleExpanded"
      @keydown.enter="handleToggleKeydown"
      @keydown.space="handleToggleKeydown"
    >
      <div class="flex min-w-0 items-center justify-end gap-1">
        <span class="min-w-0 break-words">
          {{ display.summary }}
        </span>
        <ChevronDown
          v-if="hasExpandableContent"
          :class="[
            'size-3 shrink-0 opacity-60 transition-transform',
            expanded ? 'rotate-180' : '',
          ]"
        />
      </div>

      <div
        v-if="showExpandedContent"
        class="mt-1 space-y-1 border-r border-foreground/10 pr-2 select-text"
        @click.stop
        @keydown.stop
      >
        <div v-if="display.detail" class="break-words opacity-85">
          {{ display.detail }}
        </div>

        <dl v-if="hasFacts" class="space-y-0.5 opacity-75">
          <div
            v-for="fact in display.facts"
            :key="`${fact.label}:${fact.value}`"
            class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-2"
          >
            <dt>{{ fact.label }}</dt>
            <dd class="min-w-0 break-words">
              {{ fact.value }}
            </dd>
          </div>
        </dl>
      </div>
    </div>
  </div>
</template>
