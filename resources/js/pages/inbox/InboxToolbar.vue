<!--
  文件说明：收件箱页面片段，承接收件箱列表、时间线和右侧上下文信息。
-->
<script setup lang="ts">
import FilterPopover from '@/components/common/FilterPopover.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useI18n } from '@/composables/useI18n';
import systemRoutes from '@/routes/admin';
import type { AppPageProps } from '@/types';
import type {
  EnabledWebChannelData,
  InboxTabCountsData,
  InboxView,
  UserOptionData,
} from '@/types/generated';
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown, Search, X } from 'lucide-vue-next';
import { computed, onUnmounted, ref, watch } from 'vue';

interface Props {
  currentView: InboxView;
  currentChannelId: string | null;
  currentAssignee: string | null;
  currentSearch: string | null;
  currentImportantOnly: boolean;
  currentConversationId: string | null;
  enabledWebChannels: EnabledWebChannelData[];
  teammates: UserOptionData[];
  tabCounts: InboxTabCountsData;
}

const props = defineProps<Props>();

const { t } = useI18n();
const page = usePage<AppPageProps>();

const ANY_VALUE = '__any__';
const UNASSIGNED_VALUE = 'unassigned';

interface TabDefinition {
  view: InboxView;
  label: string;
  count: number | null;
}

/**
 * closed 不展示待关注数量。
 */
const primaryTabs = computed<TabDefinition[]>(() => [
  { view: 'pending', label: t('排队中'), count: props.tabCounts.pending },
  { view: 'mine', label: t('我负责的'), count: props.tabCounts.mine },
  { view: 'ai', label: t('AI 接待中'), count: props.tabCounts.ai },
]);

const moreTabs = computed<TabDefinition[]>(() => [
  { view: 'teammates', label: t('同事'), count: props.tabCounts.teammates },
  { view: 'closed', label: t('已关闭'), count: null },
]);

const isMoreView = computed(() =>
  moreTabs.value.some((tab) => tab.view === props.currentView),
);

const activeMoreTab = computed<TabDefinition | null>(
  () => moreTabs.value.find((tab) => tab.view === props.currentView) ?? null,
);

const moreTriggerLabel = computed(
  () => activeMoreTab.value?.label ?? t('更多'),
);

const moreCount = computed(() =>
  moreTabs.value.reduce((total, tab) => total + (tab.count ?? 0), 0),
);

const moreTriggerCount = computed(() =>
  activeMoreTab.value ? activeMoreTab.value.count : moreCount.value,
);

const channelSelectValue = computed(() => props.currentChannelId ?? ANY_VALUE);
const assigneeSelectValue = computed(() => props.currentAssignee ?? ANY_VALUE);
const searchValue = ref(props.currentSearch ?? '');
let searchTimer: number | null = null;

watch(
  () => props.currentSearch,
  (value) => {
    const nextValue = value ?? '';
    if (searchValue.value !== nextValue) {
      searchValue.value = nextValue;
    }
  },
);

onUnmounted(() => {
  clearSearchTimer();
});

const activeFilterCount = computed(() => {
  let count = 0;
  if (props.currentChannelId) count += 1;
  if (props.currentAssignee) count += 1;
  if (props.currentImportantOnly) count += 1;
  return count;
});

function buildUrl(overrides: Record<string, string | null>): string {
  const query: Record<string, string> = {};

  if (props.currentView) {
    query.view = props.currentView;
  }
  if (props.currentChannelId) {
    query.channel = props.currentChannelId;
  }
  if (props.currentAssignee) {
    query.assignee = props.currentAssignee;
  }
  if (props.currentSearch) {
    query.search = props.currentSearch;
  }
  if (props.currentImportantOnly) {
    query.important = '1';
  }
  if (props.currentConversationId) {
    query.conversation_id = props.currentConversationId;
  }

  for (const [key, value] of Object.entries(overrides)) {
    if (value === null) {
      delete query[key];
    } else {
      query[key] = value;
    }
  }

  return systemRoutes.inbox.show.url({ query });
}

function navigatePartial(url: string, replace = false): void {
  router.get(
    url,
    {},
    {
      preserveScroll: true,
      preserveState: true,
      replace,
      only: [
        'current_view',
        'current_channel_id',
        'current_assignee',
        'current_search',
        'current_important_only',
        'current_conversation_id',
        'conversation_list',
        'selection',
        'tab_counts',
      ],
    },
  );
}

function selectView(view: InboxView): void {
  if (view === props.currentView) {
    return;
  }
  navigatePartial(buildUrl({ view, conversation_id: null }));
}

function onChannelChange(value: string): void {
  const channel = value === ANY_VALUE ? null : value;
  navigatePartial(buildUrl({ channel, conversation_id: null }));
}

function onAssigneeChange(value: string): void {
  const assignee = value === ANY_VALUE ? null : value;
  navigatePartial(buildUrl({ assignee, conversation_id: null }));
}

function onImportantOnlyChange(value: boolean): void {
  navigatePartial(
    buildUrl({ important: value ? '1' : null, conversation_id: null }),
  );
}

function clearSearchTimer(): void {
  if (searchTimer) {
    window.clearTimeout(searchTimer);
    searchTimer = null;
  }
}

function onSearchInput(value: string | number): void {
  searchValue.value = String(value);
  scheduleSearch();
}

function onSearchEnter(event: KeyboardEvent): void {
  if (event.isComposing) return;
  event.preventDefault();
  commitSearch();
}

function scheduleSearch(): void {
  clearSearchTimer();
  searchTimer = window.setTimeout(() => {
    searchTimer = null;
    commitSearch();
  }, 300);
}

function commitSearch(value = searchValue.value): void {
  clearSearchTimer();

  const search = value.trim();
  if (search === (props.currentSearch ?? '')) {
    return;
  }

  navigatePartial(
    buildUrl({ search: search === '' ? null : search, conversation_id: null }),
    true,
  );
}

function clearSearch(): void {
  searchValue.value = '';
  commitSearch('');
}

function clearFilters(): void {
  if (activeFilterCount.value === 0) return;
  navigatePartial(
    buildUrl({
      channel: null,
      assignee: null,
      important: null,
      conversation_id: null,
    }),
  );
}

function formatTabCount(value: number): string {
  if (value > 99) return '99+';
  return String(value);
}

const currentUserOption = computed<UserOptionData | null>(() => {
  const user = page.props.auth.user;
  if (!user) {
    return null;
  }
  return {
    id: String(user.id),
    name: user.name,
    email: user.email ?? '',
  };
});
</script>

<template>
  <div class="flex shrink-0 flex-col border-b">
    <div class="px-2 pt-2">
      <div class="flex items-center gap-2">
        <div class="relative min-w-0 flex-1">
          <Search
            class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            :model-value="searchValue"
            class="h-8 pr-8 pl-8 text-xs"
            @update:model-value="onSearchInput"
            @keydown.enter="onSearchEnter"
          />
          <Button
            v-if="searchValue"
            type="button"
            variant="ghost"
            size="icon"
            class="absolute top-1/2 right-1 size-6 -translate-y-1/2"
            :title="t('清空搜索')"
            @click="clearSearch"
          >
            <X class="size-3.5" />
          </Button>
        </div>

        <FilterPopover
          :active-count="activeFilterCount"
          badge-variant="secondary"
          badge-class="h-4 min-w-4 text-[10px]"
          icon-class="size-3.5"
          trigger-class="h-8 shrink-0 gap-1 px-2 text-xs"
          :side-offset="4"
          @clear="clearFilters"
        >
          <div class="space-y-3 p-3">
            <div class="space-y-1.5">
              <Label class="text-xs text-muted-foreground">
                {{ t('渠道') }}
              </Label>
              <Select
                :model-value="channelSelectValue"
                @update:model-value="
                  (value) =>
                    onChannelChange(typeof value === 'string' ? value : '')
                "
              >
                <SelectTrigger class="h-8 w-full text-xs">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem :value="ANY_VALUE">
                    {{ t('全部渠道') }}
                  </SelectItem>
                  <SelectItem
                    v-for="channel in props.enabledWebChannels"
                    :key="channel.id"
                    :value="channel.id"
                  >
                    {{ channel.name }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div class="space-y-1.5">
              <Label class="text-xs text-muted-foreground">
                {{ t('负责人') }}
              </Label>
              <Select
                :model-value="assigneeSelectValue"
                @update:model-value="
                  (value) =>
                    onAssigneeChange(typeof value === 'string' ? value : '')
                "
              >
                <SelectTrigger class="h-8 w-full text-xs">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem :value="ANY_VALUE">
                    {{ t('全部负责人') }}
                  </SelectItem>
                  <SelectItem :value="UNASSIGNED_VALUE">
                    {{ t('未分配') }}
                  </SelectItem>
                  <SelectItem
                    v-if="currentUserOption"
                    :value="currentUserOption.id"
                  >
                    {{ currentUserOption.name }}
                  </SelectItem>
                  <SelectItem
                    v-for="teammate in props.teammates"
                    :key="teammate.id"
                    :value="teammate.id"
                  >
                    {{ teammate.name }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div class="flex items-center justify-between gap-3 pt-1">
              <Label for="inbox-important-only" class="text-xs">
                {{ t('仅重点客户') }}
              </Label>
              <Switch
                id="inbox-important-only"
                :model-value="props.currentImportantOnly"
                @update:model-value="onImportantOnlyChange(Boolean($event))"
              />
            </div>
          </div>
        </FilterPopover>
      </div>
    </div>

    <div class="pt-2">
      <div class="flex items-stretch" role="tablist">
        <div class="flex min-w-0 flex-1 items-stretch justify-between pl-2">
          <button
            v-for="tab in primaryTabs"
            :key="tab.view"
            type="button"
            role="tab"
            :aria-selected="tab.view === props.currentView"
            :title="
              tab.count !== null && tab.count > 0
                ? `${tab.label} (${tab.count})`
                : tab.label
            "
            class="relative h-8 min-w-0 rounded-md px-1 text-center text-sm font-medium transition-colors"
            :class="
              tab.view === props.currentView
                ? 'text-foreground'
                : 'text-muted-foreground hover:text-foreground'
            "
            @click="selectView(tab.view)"
          >
            <span class="block truncate">{{ tab.label }}</span>
            <Badge
              v-if="tab.count !== null && tab.count > 0"
              :variant="
                tab.view === props.currentView ? 'default' : 'secondary'
              "
              class="pointer-events-none absolute -top-1 -right-1 h-4 min-w-4 rounded-full px-1 text-[10px] leading-none tabular-nums shadow-sm ring-1 ring-background"
            >
              {{ formatTabCount(tab.count) }}
            </Badge>
            <span
              v-if="tab.view === props.currentView"
              aria-hidden="true"
              class="absolute right-1 -bottom-px left-1 h-0.5 rounded bg-primary"
            />
          </button>
        </div>

        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <button
              type="button"
              role="tab"
              :aria-selected="isMoreView"
              :title="moreTriggerLabel"
              class="relative h-8 w-24 shrink-0 rounded-md text-center text-sm font-medium transition-colors"
              :class="
                isMoreView
                  ? 'text-foreground'
                  : 'text-muted-foreground hover:text-foreground'
              "
            >
              <span class="flex min-w-0 items-center justify-center gap-0.5">
                <span class="truncate">{{ moreTriggerLabel }}</span>
                <ChevronDown class="size-3.5 opacity-70" />
              </span>
              <Badge
                v-if="moreTriggerCount !== null && moreTriggerCount > 0"
                :variant="isMoreView ? 'default' : 'secondary'"
                class="pointer-events-none absolute -top-1 -right-1 h-4 min-w-4 rounded-full px-1 text-[10px] leading-none tabular-nums shadow-sm ring-1 ring-background"
              >
                {{ formatTabCount(moreTriggerCount) }}
              </Badge>
              <span
                v-if="isMoreView"
                aria-hidden="true"
                class="absolute right-0.5 -bottom-px left-0.5 h-0.5 rounded bg-primary"
              />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" class="w-26 !min-w-26">
            <DropdownMenuItem
              v-for="tab in moreTabs"
              :key="tab.view"
              class="flex items-center justify-between gap-2"
              :class="{
                'bg-muted text-foreground': tab.view === props.currentView,
              }"
              @select="selectView(tab.view)"
            >
              <span>{{ tab.label }}</span>
              <Badge
                v-if="tab.count !== null && tab.count > 0"
                variant="secondary"
                class="h-4 min-w-4 rounded-full px-1 text-[10px] leading-none tabular-nums"
              >
                {{ formatTabCount(tab.count) }}
              </Badge>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  </div>
</template>
