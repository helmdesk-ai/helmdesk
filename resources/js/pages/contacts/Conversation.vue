<!--
  文件说明：联系人模块页面，承接联系人列表、详情抽屉、会话记录和筛选交互。
-->
<script setup lang="ts">
import FilterPopover from '@/components/common/FilterPopover.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import workspace from '@/routes/workspace';
import type {
  ListConversationItemData,
  ShowConversationListPagePropsData,
} from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';
import { ChevronLeft, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

import ContactDetailDrawer from './ContactDetailDrawer.vue';
import ConversationDetailDrawer from './ConversationDetailDrawer.vue';
import ConversationFilterBasicPanel from './ConversationFilterBasicPanel.vue';
import ConversationFilterReplyPanel from './ConversationFilterReplyPanel.vue';
import ConversationListTable from './ConversationListTable.vue';

const { t } = useI18n();
const props = defineProps<ShowConversationListPagePropsData>();
const currentWorkspace = useRequiredWorkspace();

const readSelectedConversationIdFromUrl = (): string | null => {
  if (typeof window === 'undefined') {
    return null;
  }

  return new URL(window.location.href).searchParams.get('conversation');
};

const readViewingContactIdFromUrl = (): string | null => {
  if (typeof window === 'undefined') {
    return null;
  }

  return new URL(window.location.href).searchParams.get('contact');
};

const selectedConversationId = ref<string | null>(
  readSelectedConversationIdFromUrl(),
);
const viewingContactId = ref<string | null>(
  selectedConversationId.value !== null ? readViewingContactIdFromUrl() : null,
);
const searchInput = ref(props.search ?? '');
const selectedStatus = ref(props.current_status ?? 'all');
const selectedInboxStatus = ref(props.current_inbox_status ?? 'all');
const selectedVisitorReplyStatus = ref(
  props.current_visitor_reply_status ?? 'all',
);
const selectedAssignedUserId = ref(props.current_assigned_user_id ?? 'all');
const selectedReceptionPlanId = ref(props.current_reception_plan_id ?? 'all');

const filterPanelOpen = ref(false);
type FilterPanelTab = 'basic' | 'reply';
const activeFilterPanelTab = ref<FilterPanelTab>('basic');

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const buildQuery = (page?: number) => ({
  page: page && page > 1 ? page : undefined,
  search: searchInput.value || undefined,
  status: selectedStatus.value !== 'all' ? selectedStatus.value : undefined,
  inbox_status:
    selectedInboxStatus.value !== 'all' ? selectedInboxStatus.value : undefined,
  visitor_reply_status:
    selectedVisitorReplyStatus.value !== 'all'
      ? selectedVisitorReplyStatus.value
      : undefined,
  assigned_user_id:
    selectedAssignedUserId.value !== 'all'
      ? selectedAssignedUserId.value
      : undefined,
  reception_plan_id:
    selectedReceptionPlanId.value !== 'all'
      ? selectedReceptionPlanId.value
      : undefined,
  conversation: selectedConversationId.value || undefined,
  contact: viewingContactId.value || undefined,
});

const navigate = (page?: number) => {
  router.get(
    workspace.conversations.index.url(currentWorkspace.value.slug, {
      query: buildQuery(page),
    }),
    {},
    { preserveScroll: true, preserveState: true },
  );
};

const buildConversationPageUrl = (page: number): string =>
  workspace.conversations.index.url(currentWorkspace.value.slug, {
    query: buildQuery(page),
  });

const selectedConversation = computed(
  () =>
    props.conversation_list.find(
      (conversationItem) =>
        conversationItem.id === selectedConversationId.value,
    ) ?? null,
);

const activeBasicFilterCount = computed(() => {
  let count = 0;
  if (selectedStatus.value !== 'all') {
    count += 1;
  }
  if (selectedInboxStatus.value !== 'all') {
    count += 1;
  }
  if (selectedAssignedUserId.value !== 'all') {
    count += 1;
  }
  if (selectedReceptionPlanId.value !== 'all') {
    count += 1;
  }
  return count;
});

const activeVisitorReplyFilterCount = computed(() =>
  selectedVisitorReplyStatus.value !== 'all' ? 1 : 0,
);

const totalActiveFilterCount = computed(
  () => activeBasicFilterCount.value + activeVisitorReplyFilterCount.value,
);
const filterGroups = computed(() => [
  {
    value: 'basic',
    label: t('基本'),
    count: activeBasicFilterCount.value || undefined,
  },
  {
    value: 'reply',
    label: t('回复'),
    count: activeVisitorReplyFilterCount.value || undefined,
  },
]);

const clearAllFilters = () => {
  selectedStatus.value = 'all';
  selectedInboxStatus.value = 'all';
  selectedVisitorReplyStatus.value = 'all';
  selectedAssignedUserId.value = 'all';
  selectedReceptionPlanId.value = 'all';
  navigate();
};

watch(searchInput, () => {
  if (searchTimeout) {
    clearTimeout(searchTimeout);
  }

  searchTimeout = setTimeout(() => navigate(), 250);
});

watch(
  [
    selectedStatus,
    selectedInboxStatus,
    selectedVisitorReplyStatus,
    selectedAssignedUserId,
    selectedReceptionPlanId,
  ],
  () => {
    navigate();
  },
);

watch(
  () => props.search,
  (value) => {
    const next = value ?? '';
    if (searchInput.value !== next) {
      if (searchTimeout) {
        clearTimeout(searchTimeout);
        searchTimeout = null;
      }
      searchInput.value = next;
    }
  },
);

watch(
  () => props.current_status,
  (value) => {
    const next = value ?? 'all';
    if (selectedStatus.value !== next) {
      selectedStatus.value = next;
    }
  },
);

watch(
  () => props.current_inbox_status,
  (value) => {
    const next = value ?? 'all';
    if (selectedInboxStatus.value !== next) {
      selectedInboxStatus.value = next;
    }
  },
);

watch(
  () => props.current_visitor_reply_status,
  (value) => {
    const next = value ?? 'all';
    if (selectedVisitorReplyStatus.value !== next) {
      selectedVisitorReplyStatus.value = next;
    }
  },
);

watch(
  () => props.current_assigned_user_id,
  (value) => {
    const next = value ?? 'all';
    if (selectedAssignedUserId.value !== next) {
      selectedAssignedUserId.value = next;
    }
  },
);

watch(
  () => props.current_reception_plan_id,
  (value) => {
    const next = value ?? 'all';
    if (selectedReceptionPlanId.value !== next) {
      selectedReceptionPlanId.value = next;
    }
  },
);

const openConversation = (conversationItem: ListConversationItemData) => {
  selectedConversationId.value = conversationItem.id;
  viewingContactId.value = null;
  navigate();
};

const onDetailOpenChange = (open: boolean) => {
  if (open) {
    return;
  }

  selectedConversationId.value = null;
  viewingContactId.value = null;
  navigate();
};

const onViewContact = (contactId: string) => {
  viewingContactId.value = contactId;
  navigate();
};

const onBackToConversation = () => {
  viewingContactId.value = null;
  navigate();
};
</script>

<template>
  <AppLayout>
    <Head :title="t('会话记录')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="space-y-6">
        <HeadingSmall
          :title="t('会话记录')"
          :description="t('查看所有联系人的会话历史')"
        />

        <div class="flex flex-wrap items-center justify-end gap-3">
          <div class="relative">
            <Search
              class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input v-model="searchInput" class="h-9 w-48 pl-9 lg:w-64" />
          </div>

          <FilterPopover
            v-model:open="filterPanelOpen"
            v-model:group="activeFilterPanelTab"
            :active-count="totalActiveFilterCount"
            :groups="filterGroups"
            default-group="basic"
            @clear="clearAllFilters"
          >
            <template #basic>
              <ConversationFilterBasicPanel
                :status="selectedStatus"
                :inbox-status="selectedInboxStatus"
                :assigned-user-id="selectedAssignedUserId"
                :reception-plan-id="selectedReceptionPlanId"
                :status-options="props.status_options"
                :inbox-status-options="props.inbox_status_options"
                :teammate-options="props.teammate_options"
                :reception-plan-options="props.reception_plan_options"
                @update:status="selectedStatus = $event"
                @update:inbox-status="selectedInboxStatus = $event"
                @update:assigned-user-id="selectedAssignedUserId = $event"
                @update:reception-plan-id="selectedReceptionPlanId = $event"
              />
            </template>

            <template #reply>
              <ConversationFilterReplyPanel
                :visitor-reply-status="selectedVisitorReplyStatus"
                :visitor-reply-status-options="
                  props.visitor_reply_status_options
                "
                @update:visitor-reply-status="
                  selectedVisitorReplyStatus = $event
                "
              />
            </template>
          </FilterPopover>
        </div>

        <ConversationListTable
          :conversations="props.conversation_list"
          :pagination="props.conversation_list_pagination"
          :page-url="buildConversationPageUrl"
          @open-conversation="openConversation"
        />
      </div>
    </div>

    <Sheet
      :open="selectedConversationId !== null"
      @update:open="onDetailOpenChange"
    >
      <SheetContent side="right" class="w-full gap-0 p-0 sm:max-w-2xl">
        <div v-if="viewingContactId" class="flex h-full flex-col">
          <div class="flex items-center gap-2 border-b bg-muted/30 px-4 py-2">
            <Button
              variant="ghost"
              size="sm"
              class="-ml-2 gap-1"
              @click="onBackToConversation"
            >
              <ChevronLeft class="size-4" />
              {{ t('返回会话') }}
            </Button>
            <span class="text-xs text-muted-foreground">
              {{
                selectedConversation?.subject ||
                selectedConversation?.display_last_message_preview ||
                selectedConversation?.last_message_preview ||
                t('无主题会话')
              }}
            </span>
          </div>

          <div class="min-h-0 flex-1">
            <ContactDetailDrawer
              :key="viewingContactId"
              :contact-id="viewingContactId"
              :can-merge="false"
              :available-tags="props.available_contact_tags"
            />
          </div>
        </div>

        <ConversationDetailDrawer
          v-else-if="selectedConversationId"
          :conversation-id="selectedConversationId"
          :fallback-conversation="selectedConversation"
          @view-contact="onViewContact"
        />
      </SheetContent>
    </Sheet>
  </AppLayout>
</template>
