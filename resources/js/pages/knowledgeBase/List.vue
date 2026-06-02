<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import FilterPopover from '@/components/common/FilterPopover.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
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
import { Separator } from '@/components/ui/separator';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import KnowledgeBasesLayout from '@/layouts/KnowledgeBasesLayout.vue';
import { formatFileSize } from '@/lib/format';
import { defaultKnowledgeBaseAvatar } from '@/lib/knowledgeBaseAvatar';
import KnowledgeBaseFormPanel from '@/pages/knowledgeBase/KnowledgeBaseFormPanel.vue';
import KnowledgeDocumentFilterBasicPanel from '@/pages/knowledgeBase/KnowledgeDocumentFilterBasicPanel.vue';
import KnowledgeDocumentUploadPanel from '@/pages/knowledgeBase/KnowledgeDocumentUploadPanel.vue';
import KnowledgeGroupFormDialog from '@/pages/knowledgeBase/KnowledgeGroupFormDialog.vue';
import KnowledgeGroupRow from '@/pages/knowledgeBase/KnowledgeGroupRow.vue';
import KnowledgeManualDocumentPanel from '@/pages/knowledgeBase/KnowledgeManualDocumentPanel.vue';
import KnowledgeQaDocumentPanel from '@/pages/knowledgeBase/KnowledgeQaDocumentPanel.vue';
import KnowledgeRecallTestPanel from '@/pages/knowledgeBase/KnowledgeRecallTestPanel.vue';
import SystemKnowledgeSettingsPanel from '@/pages/knowledgeBase/SystemKnowledgeSettingsPanel.vue';
import type {
  KnowledgeBaseCategory,
  KnowledgeBaseData,
  KnowledgeGroupData,
  ListKnowledgeDocumentItemData,
  ListKnowledgeQaEntryItemData,
  ShowKnowledgeBaseListPagePropsData,
} from '@/types/generated';
import { router, useForm } from '@inertiajs/vue3';
import {
  ChevronDown,
  Library,
  MoreHorizontal,
  PenLine,
  Search,
  Settings2,
} from 'lucide-vue-next';
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue';

const KnowledgeDocumentPreviewDialog = defineAsyncComponent(
  () => import('./KnowledgeDocumentPreviewDialog.vue'),
);

const props = defineProps<ShowKnowledgeBaseListPagePropsData>();
const { t } = useI18n();

const selectedKbId = ref<string | null>(
  props.selected_knowledge_base?.id ?? null,
);
const selectedGroupId = ref<string | null>(props.selected_group_id ?? null);
const selectedStatus = ref<string>(props.current_status ?? 'all');
const searchInput = ref(props.search ?? '');
const filterPanelOpen = ref(false);
type RightPage =
  | 'knowledge_base'
  | 'knowledge_base_form'
  | 'retrieval_settings'
  | 'manual_document_form'
  | 'qa_entry_form'
  | 'recall_test';
type RightPanelQueryValue =
  | 'kb-create'
  | 'kb-edit'
  | 'settings'
  | 'manual-create'
  | 'manual-edit'
  | 'qa-create'
  | 'qa-edit'
  | 'recall';

const activeRightPage = ref<RightPage>('knowledge_base');
const panelQueryParam = 'panel';
const categoryQueryParam = 'category';
const documentQueryParam = 'document';
const entryQueryParam = 'entry';
const creatableKnowledgeBaseCategories = new Set<KnowledgeBaseCategory>([
  'standard',
  'qa',
]);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

watch(
  () => props.selected_knowledge_base?.id ?? null,
  (id) => {
    selectedKbId.value = id;
  },
);

watch(
  () => props.selected_group_id ?? null,
  (id) => {
    selectedGroupId.value = id;
  },
);

watch(
  () => props.current_status ?? 'all',
  (status) => {
    selectedStatus.value = status;
  },
);

watch(
  () => props.search ?? '',
  (search) => {
    if (searchTimeout) {
      clearTimeout(searchTimeout);
      searchTimeout = null;
    }
    searchInput.value = search;
  },
);

const selectedKb = computed(() => props.selected_knowledge_base);

const selectedKbIsQa = computed(() => selectedKb.value?.category === 'qa');

const statusOptions = computed(() =>
  selectedKbIsQa.value
    ? props.qa_status_options
    : props.document_status_options,
);

const currentListPagination = computed(() =>
  selectedKbIsQa.value
    ? props.qa_entry_list_pagination
    : props.document_list_pagination,
);

const activeBasicFilterCount = computed(() =>
  selectedStatus.value !== 'all' ? 1 : 0,
);

const totalActiveFilterCount = computed(() => activeBasicFilterCount.value);

const selectedKbGroupOptions = computed(() => {
  const groups = selectedKb.value?.document_groups ?? [];
  const options: Array<{ id: string; label: string }> = [];

  for (const group of groups) {
    options.push({ id: group.id, label: group.name });

    for (const child of group.children ?? []) {
      options.push({ id: child.id, label: `${group.name} / ${child.name}` });
    }
  }

  return options;
});

const groupLabelById = computed(() => {
  return new Map(
    selectedKbGroupOptions.value.map((group) => [group.id, group.label]),
  );
});

const overflowTooltipKey = ref<string | null>(null);

function setOverflowTooltip(event: MouseEvent, key: string): void {
  const element = event.currentTarget as HTMLElement;
  overflowTooltipKey.value =
    element.scrollWidth > element.clientWidth ? key : null;
}

function clearOverflowTooltip(key: string): void {
  if (overflowTooltipKey.value === key) {
    overflowTooltipKey.value = null;
  }
}

function documentGroupLabel(groupId: string | null): string {
  if (!groupId) {
    return t('未知分组');
  }

  return groupLabelById.value.get(groupId) ?? t('未知分组');
}

function documentTypeLabel(doc: ListKnowledgeDocumentItemData): string {
  if (doc.source_type === 'manual') {
    return t('手动内容');
  }

  switch (doc.extension?.toLowerCase()) {
    case 'md':
    case 'markdown':
      return 'Markdown';
    case 'txt':
      return t('纯文本');
    case 'docx':
      return 'Word';
    case 'pdf':
      return 'PDF';
    case 'html':
    case 'htm':
      return 'HTML';
    default:
      return doc.extension ? doc.extension.toUpperCase() : doc.mime_type;
  }
}

function normalizeKnowledgeBaseCategory(
  category: string | null,
): KnowledgeBaseCategory {
  const value = category as KnowledgeBaseCategory | null;

  return value && creatableKnowledgeBaseCategories.has(value)
    ? value
    : 'standard';
}

function buildDocumentListQuery(
  kbId: string | null,
  groupId: string | null,
): Record<string, string> {
  const query: Record<string, string> = {};
  if (kbId) {
    query.kb = kbId;
  }
  if (groupId) {
    query.group = groupId;
  }
  if (selectedStatus.value !== 'all') {
    query.status = selectedStatus.value;
  }
  if (searchInput.value.trim() !== '') {
    query.search = searchInput.value.trim();
  }
  return query;
}

function navigateTo(kbId: string | null, groupId: string | null): void {
  activeRightPage.value = 'knowledge_base';
  selectedKbId.value = kbId;
  selectedGroupId.value = groupId;

  router.get(
    KnowledgeBase.ListKnowledgeBasesAction.url({
      query: buildDocumentListQuery(kbId, groupId),
    }),
    {},
    { preserveState: true, preserveScroll: true, replace: true },
  );
}

function buildDocumentListPageUrl(page: number): string {
  const query = buildDocumentListQuery(
    selectedKbId.value,
    selectedGroupId.value,
  );
  if (page > 1) {
    query.page = String(page);
  }
  return KnowledgeBase.ListKnowledgeBasesAction.url({ query },
  );
}

watch(searchInput, () => {
  if ((props.search ?? '') === searchInput.value.trim()) {
    return;
  }

  if (searchTimeout) {
    clearTimeout(searchTimeout);
  }

  searchTimeout = setTimeout(() => {
    navigateTo(selectedKbId.value, selectedGroupId.value);
  }, 250);
});

function updateStatusFilter(status: string): void {
  selectedStatus.value = status;
  navigateTo(selectedKbId.value, selectedGroupId.value);
}

function clearAllFilters(): void {
  selectedStatus.value = 'all';
  navigateTo(selectedKbId.value, selectedGroupId.value);
}

function selectKb(kbId: string): void {
  if (selectedKbId.value === kbId) {
    if (selectedGroupId.value !== null) {
      navigateTo(kbId, null);
    }
    return;
  }
  navigateTo(kbId, null);
}

function selectGroup(kbId: string, groupId: string | null): void {
  if (selectedKbId.value === kbId && selectedGroupId.value === groupId) {
    return;
  }
  navigateTo(kbId, groupId);
}

function isGroupSelected(kbId: string, groupId: string | null): boolean {
  return (
    isKnowledgeBaseContentPage.value &&
    selectedKbId.value === kbId &&
    selectedGroupId.value === groupId
  );
}

const isKnowledgeBaseContentPage = computed(() =>
  [
    'knowledge_base',
    'manual_document_form',
    'qa_entry_form',
    'recall_test',
  ].includes(activeRightPage.value),
);

function isKnowledgeBaseRowActive(kbId: string): boolean {
  if (selectedKbId.value !== kbId) {
    return false;
  }

  if (isKnowledgeBaseContentPage.value) {
    return true;
  }

  return (
    activeRightPage.value === 'knowledge_base_form' &&
    editingKb.value?.id === kbId
  );
}

function clearTransientListState(): void {
  if (searchTimeout) {
    clearTimeout(searchTimeout);
    searchTimeout = null;
  }
  filterPanelOpen.value = false;
  previewDocumentTarget.value = null;
}

const createCategory = ref<KnowledgeBaseCategory>('standard');
const knowledgeBaseFormMode = ref<'create' | 'edit'>('create');
const editingKb = ref<KnowledgeBaseData | null>(null);

const categoryOptions = computed(() => props.category_options);

const activeCategoryLabel = computed(() => {
  if (editingKb.value) {
    return editingKb.value.category_label;
  }

  return (
    categoryOptions.value.find((o) => o.value === createCategory.value)
      ?.label ?? ''
  );
});

function openCreateDialog(category: KnowledgeBaseCategory): void {
  clearTransientListState();
  knowledgeBaseFormMode.value = 'create';
  createCategory.value = category;
  editingKb.value = null;
  activeRightPage.value = 'knowledge_base_form';
}

function openEditDialog(kb: KnowledgeBaseData): void {
  clearTransientListState();
  selectedKbId.value = kb.id;
  selectedGroupId.value = null;
  knowledgeBaseFormMode.value = 'edit';
  createCategory.value = normalizeKnowledgeBaseCategory(kb.category);
  editingKb.value = kb;
  activeRightPage.value = 'knowledge_base_form';
}

function openSystemSettingsPage(): void {
  clearTransientListState();
  activeRightPage.value = 'retrieval_settings';
}

function openRecallTestPage(): void {
  if (!selectedKb.value) {
    return;
  }
  clearTransientListState();
  activeRightPage.value = 'recall_test';
}

function returnToKnowledgeBasePage(): void {
  clearTransientListState();
  activeRightPage.value = 'knowledge_base';
}

const deleteKbId = ref<string | null>(null);
const deleteKbForm = useForm({});

const deletingKb = computed(
  () =>
    props.knowledge_base_list.find((kb) => kb.id === deleteKbId.value) ?? null,
);

function confirmDeleteKb(): void {
  const targetId = deleteKbId.value;
  if (!targetId) {
    return;
  }
  deleteKbForm.delete(
    KnowledgeBase.DeleteKnowledgeBaseAction.url({
      knowledgeBase: targetId,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        if (selectedKbId.value === targetId) {
          selectedKbId.value = null;
          selectedGroupId.value = null;
        }
        deleteKbId.value = null;
      },
    },
  );
}

const groupDialogOpen = ref(false);
const groupDialogMode = ref<'create' | 'edit'>('create');
const groupDialogKbId = ref('');
const groupDialogTarget = ref<KnowledgeGroupData | null>(null);

function openCreateGroupDialog(kbId: string): void {
  groupDialogMode.value = 'create';
  groupDialogKbId.value = kbId;
  groupDialogTarget.value = null;
  groupDialogOpen.value = true;
}

function openEditGroupDialog(kbId: string, group: KnowledgeGroupData): void {
  groupDialogMode.value = 'edit';
  groupDialogKbId.value = kbId;
  groupDialogTarget.value = group;
  groupDialogOpen.value = true;
}

const groupDialogKb = computed(
  () =>
    props.knowledge_base_list.find((kb) => kb.id === groupDialogKbId.value) ??
    null,
);

const previewDocumentTarget = ref<ListKnowledgeDocumentItemData | null>(null);
const uploadDialogOpen = ref(false);

function openUploadDialog(): void {
  if (!selectedKb.value) {
    return;
  }
  clearTransientListState();
  uploadDialogOpen.value = true;
}

const manualDialogMode = ref<'create' | 'edit'>('create');
const manualDialogTarget = ref<ListKnowledgeDocumentItemData | null>(null);

function openManualCreateDialog(): void {
  if (!selectedKb.value) {
    return;
  }
  clearTransientListState();
  manualDialogMode.value = 'create';
  manualDialogTarget.value = null;
  activeRightPage.value = 'manual_document_form';
}

function openManualEditDialog(doc: ListKnowledgeDocumentItemData): void {
  if (!selectedKb.value || doc.source_type !== 'manual') {
    return;
  }
  clearTransientListState();
  manualDialogMode.value = 'edit';
  manualDialogTarget.value = doc;
  activeRightPage.value = 'manual_document_form';
}

const manualDialogDefaultGroupId = computed(() => {
  if (selectedGroupId.value) {
    return selectedGroupId.value;
  }
  const defaultGroup = (selectedKb.value?.document_groups ?? []).find(
    (group) => group.is_default,
  );
  return defaultGroup?.id ?? selectedKbGroupOptions.value[0]?.id ?? null;
});

const qaDialogMode = ref<'create' | 'edit'>('create');
const qaDialogTarget = ref<ListKnowledgeQaEntryItemData | null>(null);

function openQaCreateDialog(): void {
  if (!selectedKb.value || !selectedKbIsQa.value) {
    return;
  }
  clearTransientListState();
  qaDialogMode.value = 'create';
  qaDialogTarget.value = null;
  activeRightPage.value = 'qa_entry_form';
}

function openQaEditDialog(entry: ListKnowledgeQaEntryItemData): void {
  if (!selectedKb.value || !selectedKbIsQa.value) {
    return;
  }
  clearTransientListState();
  qaDialogMode.value = 'edit';
  qaDialogTarget.value = entry;
  activeRightPage.value = 'qa_entry_form';
}

function findKnowledgeBaseById(kbId: string | null): KnowledgeBaseData | null {
  if (!kbId) {
    return null;
  }

  return props.knowledge_base_list.find((kb) => kb.id === kbId) ?? null;
}

function findCurrentDocumentById(
  documentId: string | null,
): ListKnowledgeDocumentItemData | null {
  if (!documentId) {
    return null;
  }

  return props.document_list.find((doc) => doc.id === documentId) ?? null;
}

function findCurrentQaEntryById(
  entryId: string | null,
): ListKnowledgeQaEntryItemData | null {
  if (!entryId) {
    return null;
  }

  return props.qa_entry_list.find((entry) => entry.id === entryId) ?? null;
}

function resetRightPanelState(): void {
  activeRightPage.value = 'knowledge_base';
  knowledgeBaseFormMode.value = 'create';
  editingKb.value = null;
  manualDialogMode.value = 'create';
  manualDialogTarget.value = null;
  qaDialogMode.value = 'create';
  qaDialogTarget.value = null;
}

function applyRightPanelStateFromUrl(): void {
  if (typeof window === 'undefined') {
    return;
  }

  const url = new URL(window.location.href);
  const panel = url.searchParams.get(
    panelQueryParam,
  ) as RightPanelQueryValue | null;

  resetRightPanelState();

  if (panel === 'settings') {
    activeRightPage.value = 'retrieval_settings';
    return;
  }

  if (panel === 'recall') {
    if (selectedKb.value) {
      activeRightPage.value = 'recall_test';
    }
    return;
  }

  if (panel === 'kb-create') {
    knowledgeBaseFormMode.value = 'create';
    createCategory.value = normalizeKnowledgeBaseCategory(
      url.searchParams.get(categoryQueryParam),
    );
    activeRightPage.value = 'knowledge_base_form';
    return;
  }

  if (panel === 'kb-edit') {
    const kb = findKnowledgeBaseById(selectedKbId.value);
    if (kb) {
      knowledgeBaseFormMode.value = 'edit';
      createCategory.value = normalizeKnowledgeBaseCategory(kb.category);
      editingKb.value = kb;
      activeRightPage.value = 'knowledge_base_form';
    }
    return;
  }

  if (panel === 'manual-create') {
    if (selectedKb.value && !selectedKbIsQa.value) {
      manualDialogMode.value = 'create';
      activeRightPage.value = 'manual_document_form';
    }
    return;
  }

  if (panel === 'manual-edit') {
    const doc = findCurrentDocumentById(
      url.searchParams.get(documentQueryParam),
    );
    if (
      selectedKb.value &&
      !selectedKbIsQa.value &&
      doc?.source_type === 'manual'
    ) {
      manualDialogMode.value = 'edit';
      manualDialogTarget.value = doc;
      activeRightPage.value = 'manual_document_form';
    }
    return;
  }

  if (panel === 'qa-create') {
    if (selectedKb.value && selectedKbIsQa.value) {
      qaDialogMode.value = 'create';
      activeRightPage.value = 'qa_entry_form';
    }
    return;
  }

  if (panel === 'qa-edit') {
    const entry = findCurrentQaEntryById(url.searchParams.get(entryQueryParam));
    if (selectedKb.value && selectedKbIsQa.value && entry) {
      qaDialogMode.value = 'edit';
      qaDialogTarget.value = entry;
      activeRightPage.value = 'qa_entry_form';
    }
  }
}

function clearRightPanelQueryParams(url: URL): void {
  url.searchParams.delete(panelQueryParam);
  url.searchParams.delete(categoryQueryParam);
  url.searchParams.delete(documentQueryParam);
  url.searchParams.delete(entryQueryParam);
}

function syncKnowledgeBaseScopeParams(url: URL): void {
  if (selectedKbId.value) {
    url.searchParams.set('kb', selectedKbId.value);
  } else {
    url.searchParams.delete('kb');
  }

  if (selectedGroupId.value) {
    url.searchParams.set('group', selectedGroupId.value);
  } else {
    url.searchParams.delete('group');
  }
}

function writeKnowledgeBaseUrlState(): void {
  if (typeof window === 'undefined') {
    return;
  }

  const url = new URL(window.location.href);

  syncKnowledgeBaseScopeParams(url);
  clearRightPanelQueryParams(url);

  if (activeRightPage.value === 'retrieval_settings') {
    url.searchParams.set(panelQueryParam, 'settings');
  } else if (activeRightPage.value === 'recall_test') {
    url.searchParams.set(panelQueryParam, 'recall');
  } else if (activeRightPage.value === 'knowledge_base_form') {
    if (knowledgeBaseFormMode.value === 'edit' && editingKb.value) {
      url.searchParams.set('kb', editingKb.value.id);
      url.searchParams.delete('group');
      url.searchParams.set(panelQueryParam, 'kb-edit');
    } else {
      url.searchParams.set(panelQueryParam, 'kb-create');
      url.searchParams.set(categoryQueryParam, createCategory.value);
    }
  } else if (activeRightPage.value === 'manual_document_form') {
    url.searchParams.set(
      panelQueryParam,
      manualDialogMode.value === 'edit' ? 'manual-edit' : 'manual-create',
    );
    if (manualDialogMode.value === 'edit' && manualDialogTarget.value) {
      url.searchParams.set(documentQueryParam, manualDialogTarget.value.id);
    }
  } else if (activeRightPage.value === 'qa_entry_form') {
    url.searchParams.set(
      panelQueryParam,
      qaDialogMode.value === 'edit' ? 'qa-edit' : 'qa-create',
    );
    if (qaDialogMode.value === 'edit' && qaDialogTarget.value) {
      url.searchParams.set(entryQueryParam, qaDialogTarget.value.id);
    }
  }

  window.history.replaceState(window.history.state, '', url.toString());
}

applyRightPanelStateFromUrl();

onMounted(() => {
  writeKnowledgeBaseUrlState();
});

watch(
  [
    selectedKbId,
    selectedGroupId,
    activeRightPage,
    createCategory,
    knowledgeBaseFormMode,
    () => editingKb.value?.id ?? null,
    manualDialogMode,
    () => manualDialogTarget.value?.id ?? null,
    qaDialogMode,
    () => qaDialogTarget.value?.id ?? null,
  ],
  writeKnowledgeBaseUrlState,
);

const deleteDocumentTarget = ref<ListKnowledgeDocumentItemData | null>(null);
const deleteDocumentForm = useForm({});
const reindexDocumentForm = useForm({});
const reindexingDocumentId = ref<string | null>(null);

function reindexDocument(doc: ListKnowledgeDocumentItemData): void {
  const kb = selectedKb.value;
  if (!kb) {
    return;
  }
  reindexingDocumentId.value = doc.id;
  reindexDocumentForm.post(
    KnowledgeBase.Indexing.ReindexKnowledgeDocumentAction.url({
      knowledgeBase: kb.id,
      document: doc.id,
    }),
    {
      preserveScroll: true,
      onFinish: () => {
        reindexingDocumentId.value = null;
      },
    },
  );
}

function overallBadgeVariant(
  status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
  switch (status) {
    case 'failed':
      return 'destructive';
    case 'succeeded':
    case 'indexed':
      return 'default';
    case 'idle':
      return 'outline';
    default:
      return 'secondary';
  }
}

const deleteQaEntryTarget = ref<ListKnowledgeQaEntryItemData | null>(null);
const deleteQaEntryForm = useForm({});
const moveDocumentTarget = ref<ListKnowledgeDocumentItemData | null>(null);
const moveDocumentForm = useForm({
  group_id: '',
});
const moveQaEntryTarget = ref<ListKnowledgeQaEntryItemData | null>(null);
const moveQaEntryForm = useForm({
  group_id: '',
});

function openMoveDocumentDialog(doc: ListKnowledgeDocumentItemData): void {
  moveDocumentTarget.value = doc;
  moveDocumentForm.group_id =
    doc.group_id ?? selectedKbGroupOptions.value[0]?.id ?? '';
  moveDocumentForm.clearErrors();
}

function moveDocument(): void {
  const target = moveDocumentTarget.value;
  const kb = selectedKb.value;
  if (!target || !kb || !moveDocumentForm.group_id) {
    return;
  }

  moveDocumentForm.put(
    KnowledgeBase.Document.MoveKnowledgeDocumentAction.url({
      knowledgeBase: kb.id,
      document: target.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        moveDocumentTarget.value = null;
      },
    },
  );
}

function openMoveQaEntryDialog(entry: ListKnowledgeQaEntryItemData): void {
  moveQaEntryTarget.value = entry;
  moveQaEntryForm.group_id =
    entry.group_id ?? selectedKbGroupOptions.value[0]?.id ?? '';
  moveQaEntryForm.clearErrors();
}

function moveQaEntry(): void {
  const target = moveQaEntryTarget.value;
  const kb = selectedKb.value;
  if (!target || !kb || !moveQaEntryForm.group_id) {
    return;
  }

  moveQaEntryForm.put(
    KnowledgeBase.Qa.MoveKnowledgeQaEntryAction.url({
      knowledgeBase: kb.id,
      entry: target.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        moveQaEntryTarget.value = null;
      },
    },
  );
}

function confirmDeleteDocument(): void {
  const target = deleteDocumentTarget.value;
  const kb = selectedKb.value;
  if (!target || !kb) {
    return;
  }
  deleteDocumentForm.delete(
    KnowledgeBase.Document.DeleteKnowledgeDocumentAction.url({
      knowledgeBase: kb.id,
      document: target.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deleteDocumentTarget.value = null;
      },
    },
  );
}

function confirmDeleteQaEntry(): void {
  const target = deleteQaEntryTarget.value;
  const kb = selectedKb.value;
  if (!target || !kb) {
    return;
  }
  deleteQaEntryForm.delete(
    KnowledgeBase.Qa.DeleteKnowledgeQaEntryAction.url({
      knowledgeBase: kb.id,
      entry: target.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deleteQaEntryTarget.value = null;
      },
    },
  );
}
</script>

<template>
  <AppLayout>
    <KnowledgeBasesLayout>
      <template #sidebar>
        <div class="flex min-h-0 flex-1 flex-col">
          <div class="px-3 pb-2">
            <div class="flex gap-2">
              <DropdownMenu>
                <DropdownMenuTrigger as-child>
                  <Button
                    type="button"
                    :variant="
                      activeRightPage === 'knowledge_base_form' && !editingKb
                        ? 'secondary'
                        : 'outline'
                    "
                    size="sm"
                    class="min-w-0 flex-1 justify-center"
                  >
                    {{ t('新建知识库') }}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-56">
                  <DropdownMenuItem
                    v-for="cat in categoryOptions"
                    :key="cat.value"
                    @click="
                      openCreateDialog(
                        String(cat.value) as KnowledgeBaseCategory,
                      )
                    "
                  >
                    <div class="flex flex-col gap-0.5">
                      <span class="font-medium">{{ cat.label }}</span>
                      <span
                        v-if="cat.description"
                        class="text-xs text-muted-foreground"
                      >
                        {{ cat.description }}
                      </span>
                    </div>
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>

              <TooltipProvider>
                <Tooltip>
                  <TooltipTrigger as-child>
                    <Button
                      type="button"
                      :variant="
                        activeRightPage === 'retrieval_settings'
                          ? 'secondary'
                          : 'outline'
                      "
                      size="icon"
                      class="h-8 w-8 shrink-0"
                      :aria-label="t('检索配置')"
                      @click="openSystemSettingsPage"
                    >
                      <Settings2 class="h-4 w-4" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent>
                    {{ t('检索配置') }}
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
            </div>
          </div>

          <Separator />

          <div class="flex-1 overflow-y-auto py-2">
            <div
              v-if="props.knowledge_base_list.length === 0"
              class="px-4 py-8 text-center text-sm text-muted-foreground"
            >
              {{ t('暂无知识库') }}
            </div>

            <div
              v-for="kb in props.knowledge_base_list"
              :key="kb.id"
              class="mb-1"
            >
              <div
                class="group flex items-center gap-0.5 px-2"
                :class="{
                  'rounded-md bg-accent/40': isKnowledgeBaseRowActive(kb.id),
                }"
              >
                <button
                  type="button"
                  class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-1.5 py-1.5 text-left text-sm hover:bg-accent/40"
                  :class="{
                    'font-medium': isKnowledgeBaseRowActive(kb.id),
                  }"
                  @click="selectKb(kb.id)"
                >
                  <img
                    :src="kb.avatar_url ?? defaultKnowledgeBaseAvatar"
                    :alt="kb.name"
                    class="h-5 w-5 shrink-0 rounded object-cover"
                  />
                  <span class="min-w-0 flex-1 truncate">{{ kb.name }}</span>
                  <Badge
                    variant="secondary"
                    class="shrink-0 px-1.5 py-0 text-[10px] font-normal"
                  >
                    {{ kb.category_label }}
                  </Badge>
                </button>

                <div
                  class="flex shrink-0 items-center text-muted-foreground/50 hover:text-muted-foreground"
                  :class="{
                    'text-muted-foreground': isKnowledgeBaseRowActive(kb.id),
                  }"
                >
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="h-6 w-6"
                    :class="
                      activeRightPage === 'knowledge_base_form' &&
                      editingKb?.id === kb.id
                        ? 'bg-background text-foreground shadow-sm'
                        : ''
                    "
                    :aria-label="t('编辑')"
                    @click.stop="openEditDialog(kb)"
                  >
                    <PenLine class="h-3.5 w-3.5" />
                  </Button>
                  <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="h-6 w-6"
                        :aria-label="t('更多操作')"
                        @click.stop
                      >
                        <MoreHorizontal class="h-3.5 w-3.5" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-36">
                      <DropdownMenuItem
                        @click.stop="openCreateGroupDialog(kb.id)"
                      >
                        {{ t('新建分组') }}
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem
                        class="text-destructive focus:text-destructive"
                        @click.stop="deleteKbId = kb.id"
                      >
                        {{ t('删除') }}
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </div>

              <!-- Group tree -->
              <div
                class="mt-0.5 ml-4 space-y-0.5 border-l border-border/60 pl-2"
              >
                <button
                  type="button"
                  class="flex w-full items-center gap-1.5 rounded-md px-1 py-1 text-sm hover:bg-accent/50"
                  :class="{
                    'bg-accent text-accent-foreground hover:bg-accent':
                      isGroupSelected(kb.id, null),
                  }"
                  @click="selectGroup(kb.id, null)"
                >
                  <Library class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span class="flex-1 truncate text-left">
                    {{ kb.category === 'qa' ? t('全部问答') : t('全部文档') }}
                  </span>
                </button>

                <div
                  v-for="group in kb.document_groups"
                  :key="group.id"
                  class="space-y-0.5"
                >
                  <KnowledgeGroupRow
                    :group="group"
                    :knowledge-base-id="kb.id"
                    :selected="isGroupSelected(kb.id, group.id)"
                    @select="selectGroup(kb.id, group.id)"
                    @edit="openEditGroupDialog(kb.id, group)"
                  />

                  <div
                    v-if="group.children && group.children.length > 0"
                    class="ml-4 space-y-0.5"
                  >
                    <KnowledgeGroupRow
                      v-for="child in group.children"
                      :key="child.id"
                      :group="child"
                      :knowledge-base-id="kb.id"
                      :selected="isGroupSelected(kb.id, child.id)"
                      @select="selectGroup(kb.id, child.id)"
                      @edit="openEditGroupDialog(kb.id, child)"
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>

      <KnowledgeBaseFormPanel
        v-if="activeRightPage === 'knowledge_base_form'"
        :key="`kb-form:${knowledgeBaseFormMode}:${editingKb?.id ?? createCategory}`"
        :mode="knowledgeBaseFormMode"
        :category="createCategory"
        :category-label="activeCategoryLabel"
        :knowledge-base="editingKb"
        @cancel="returnToKnowledgeBasePage"
        @saved="returnToKnowledgeBasePage"
      />

      <SystemKnowledgeSettingsPanel
        v-else-if="activeRightPage === 'retrieval_settings'"
        :settings="props.system_knowledge_settings"
        :embedding-model-options="props.embedding_model_options"
        :rerank-model-options="props.rerank_model_options"
        :summary-model-options="props.summary_model_options"
        :chunking-strategy-options="props.chunking_strategy_options"
      />

      <KnowledgeManualDocumentPanel
        v-else-if="activeRightPage === 'manual_document_form' && selectedKb"
        :key="`manual:${manualDialogMode}:${manualDialogTarget?.id ?? selectedKb.id}`"
        :mode="manualDialogMode"
        :knowledge-base-id="selectedKb.id"
        :group-options="selectedKbGroupOptions"
        :default-group-id="manualDialogDefaultGroupId"
        :document="manualDialogTarget"
        @cancel="returnToKnowledgeBasePage"
        @saved="returnToKnowledgeBasePage"
      />

      <KnowledgeQaDocumentPanel
        v-else-if="activeRightPage === 'qa_entry_form' && selectedKb"
        :key="`qa:${qaDialogMode}:${qaDialogTarget?.id ?? selectedKb.id}`"
        :mode="qaDialogMode"
        :knowledge-base-id="selectedKb.id"
        :group-options="selectedKbGroupOptions"
        :default-group-id="manualDialogDefaultGroupId"
        :entry="qaDialogTarget"
        @cancel="returnToKnowledgeBasePage"
        @saved="returnToKnowledgeBasePage"
      />

      <KnowledgeRecallTestPanel
        v-else-if="activeRightPage === 'recall_test' && selectedKb"
        :key="`recall:${selectedKb.id}`"
        :knowledge-base-id="selectedKb.id"
        :mode-options="props.search_mode_options"
        @cancel="returnToKnowledgeBasePage"
      />

      <div v-else>
        <div class="mb-6 flex items-start justify-between gap-4">
          <header v-if="selectedKb" class="min-w-0 flex-1">
            <div class="mb-0.5 flex items-center gap-2">
              <h3 class="truncate text-base font-medium">
                {{ selectedKb.name }}
              </h3>
              <Badge variant="secondary" class="shrink-0">
                {{ selectedKb.category_label }}
              </Badge>
            </div>
            <p class="text-sm text-muted-foreground">
              {{
                selectedKb.description ||
                (selectedKbIsQa
                  ? t(
                      '管理当前知识库下的问答；左侧切换分组以查看不同分组的问答。',
                    )
                  : t(
                      '管理当前知识库下的文档；左侧切换分组以查看不同分组的文档。',
                    ))
              }}
            </p>
          </header>
          <div v-else class="min-w-0 flex-1"></div>

          <div v-if="selectedKb" class="flex shrink-0 items-center gap-2">
            <Button
              v-if="selectedKbIsQa"
              type="button"
              @click="openQaCreateDialog"
            >
              {{ t('添加') }}
            </Button>
            <DropdownMenu v-else>
              <DropdownMenuTrigger as-child>
                <Button type="button">
                  {{ t('添加') }}
                  <ChevronDown class="ml-1.5 h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" class="w-48">
                <DropdownMenuItem @select="openUploadDialog">
                  {{ t('上传文档') }}
                </DropdownMenuItem>
                <DropdownMenuItem @select="openManualCreateDialog">
                  {{ t('手动添加') }}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
            <Button type="button" variant="outline" @click="openRecallTestPage">
              {{ t('检索测试') }}
            </Button>
          </div>
        </div>

        <div v-if="!selectedKb" class="flex items-center justify-center py-20">
          <div class="space-y-2 text-center">
            <Library class="mx-auto h-12 w-12 text-muted-foreground/40" />
            <p class="text-sm text-muted-foreground">
              {{ t('请从左侧选择一个知识库') }}
            </p>
          </div>
        </div>

        <div v-else class="space-y-6">
          <div class="flex flex-wrap items-center justify-end gap-3">
            <div class="relative">
              <Search
                class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
              />
              <Input v-model="searchInput" class="h-9 w-48 pl-9 lg:w-64" />
            </div>
            <FilterPopover
              v-model:open="filterPanelOpen"
              :active-count="totalActiveFilterCount"
              @clear="clearAllFilters"
            >
              <KnowledgeDocumentFilterBasicPanel
                :status="selectedStatus"
                :status-options="statusOptions"
                @update:status="updateStatusFilter"
              />
            </FilterPopover>
          </div>

          <div class="min-w-0 rounded-lg border">
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="border-b bg-muted/30 text-muted-foreground">
                  <tr class="text-left">
                    <th class="w-[38%] px-4 py-3">
                      {{ selectedKbIsQa ? t('问题') : t('文件名') }}
                    </th>
                    <th v-if="!selectedKbIsQa" class="px-4 py-3">
                      {{ t('文件类型') }}
                    </th>
                    <th class="px-4 py-3">{{ t('分组') }}</th>
                    <th v-if="!selectedKbIsQa" class="px-4 py-3">
                      {{ t('大小') }}
                    </th>
                    <th class="px-4 py-3">{{ t('状态') }}</th>
                    <th class="w-40 px-4 py-3 text-right whitespace-nowrap">
                      {{ t('操作') }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-if="
                      selectedKbIsQa
                        ? props.qa_entry_list.length === 0
                        : props.document_list.length === 0
                    "
                  >
                    <td
                      :colspan="selectedKbIsQa ? 4 : 6"
                      class="px-4 py-8 text-center text-muted-foreground"
                    >
                      {{ selectedKbIsQa ? t('暂无问答') : t('暂无文档') }}
                    </td>
                  </tr>
                  <template v-else-if="selectedKbIsQa">
                    <tr
                      v-for="entry in props.qa_entry_list"
                      :key="entry.id"
                      class="border-b last:border-b-0 hover:bg-muted/20"
                    >
                      <td class="max-w-0 px-4 py-3">
                        <TooltipProvider>
                          <Tooltip>
                            <TooltipTrigger as-child>
                              <span
                                class="block truncate font-medium"
                                @mouseenter="
                                  setOverflowTooltip($event, `qa:${entry.id}`)
                                "
                                @mouseleave="
                                  clearOverflowTooltip(`qa:${entry.id}`)
                                "
                              >
                                {{ entry.question }}
                              </span>
                            </TooltipTrigger>
                            <TooltipContent
                              v-if="overflowTooltipKey === `qa:${entry.id}`"
                              class="max-w-96 break-words"
                            >
                              {{ entry.question }}
                            </TooltipContent>
                          </Tooltip>
                        </TooltipProvider>
                      </td>
                      <td class="max-w-0 px-4 py-3 text-muted-foreground">
                        <span class="block truncate">
                          {{ documentGroupLabel(entry.group_id) }}
                        </span>
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap">
                        <TooltipProvider>
                          <Tooltip>
                            <TooltipTrigger as-child>
                              <button
                                type="button"
                                class="inline-flex max-w-56 items-center gap-2 rounded-md text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                              >
                                <Badge
                                  :variant="overallBadgeVariant(entry.status)"
                                  :title="
                                    entry.error_message ??
                                    entry.vector_error ??
                                    undefined
                                  "
                                >
                                  {{ entry.status_label }}
                                </Badge>
                              </button>
                            </TooltipTrigger>
                            <TooltipContent class="max-w-80 break-words">
                              <div
                                class="grid grid-cols-[5rem_1fr] gap-3 text-xs"
                              >
                                <span class="text-muted-foreground">
                                  {{ t('标准索引') }}
                                </span>
                                <span>
                                  {{ entry.vector_status_label }}
                                  <span
                                    v-if="
                                      entry.vector_error ?? entry.error_message
                                    "
                                    class="block pt-0.5 text-destructive"
                                  >
                                    {{
                                      entry.vector_error ?? entry.error_message
                                    }}
                                  </span>
                                </span>
                              </div>
                            </TooltipContent>
                          </Tooltip>
                        </TooltipProvider>
                      </td>
                      <td class="px-4 py-3 text-right whitespace-nowrap">
                        <div class="flex justify-end gap-1">
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="openQaEditDialog(entry)"
                          >
                            {{ t('编辑') }}
                          </Button>
                          <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8"
                                :aria-label="t('更多操作')"
                              >
                                <MoreHorizontal class="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-32">
                              <DropdownMenuItem
                                @select="openMoveQaEntryDialog(entry)"
                              >
                                {{ t('移动分组') }}
                              </DropdownMenuItem>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem
                                class="text-destructive focus:text-destructive"
                                :disabled="deleteQaEntryForm.processing"
                                @select="deleteQaEntryTarget = entry"
                              >
                                {{ t('删除') }}
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </div>
                      </td>
                    </tr>
                  </template>
                  <template v-else>
                    <tr
                      v-for="doc in props.document_list"
                      :key="doc.id"
                      class="border-b last:border-b-0 hover:bg-muted/20"
                    >
                      <td class="max-w-0 px-4 py-3">
                        <TooltipProvider>
                          <Tooltip>
                            <TooltipTrigger as-child>
                              <span
                                class="block truncate font-medium"
                                @mouseenter="
                                  setOverflowTooltip(
                                    $event,
                                    `filename:${doc.id}`,
                                  )
                                "
                                @mouseleave="
                                  clearOverflowTooltip(`filename:${doc.id}`)
                                "
                              >
                                {{ doc.original_filename }}
                              </span>
                            </TooltipTrigger>
                            <TooltipContent
                              v-if="overflowTooltipKey === `filename:${doc.id}`"
                              class="max-w-96 break-words"
                            >
                              {{ doc.original_filename }}
                            </TooltipContent>
                          </Tooltip>
                        </TooltipProvider>
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap">
                        <Badge variant="secondary" :title="doc.mime_type">
                          {{ documentTypeLabel(doc) }}
                        </Badge>
                      </td>
                      <td class="max-w-0 px-4 py-3 text-muted-foreground">
                        <span class="block truncate">
                          {{ documentGroupLabel(doc.group_id) }}
                        </span>
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap">
                        {{ formatFileSize(doc.byte_size) }}
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap">
                        <TooltipProvider>
                          <Tooltip>
                            <TooltipTrigger as-child>
                              <button
                                type="button"
                                class="inline-flex max-w-56 items-center gap-2 rounded-md text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                              >
                                <Badge
                                  :variant="
                                    overallBadgeVariant(
                                      doc.indexing.overall_status,
                                    )
                                  "
                                  :title="doc.error_message ?? undefined"
                                >
                                  {{ doc.indexing.overall_status_label }}
                                </Badge>
                              </button>
                            </TooltipTrigger>
                            <TooltipContent class="max-w-80 break-words">
                              <div class="space-y-1.5">
                                <div
                                  v-for="stage in doc.indexing.stages"
                                  :key="`${doc.id}:${stage.stage}`"
                                  class="grid grid-cols-[5rem_1fr] gap-3 text-xs"
                                >
                                  <span class="text-muted-foreground">
                                    {{ stage.stage_label }}
                                  </span>
                                  <span>
                                    {{ stage.status_label }}
                                    <span
                                      v-if="stage.error_message"
                                      class="block pt-0.5 text-destructive"
                                    >
                                      {{ stage.error_message }}
                                    </span>
                                  </span>
                                </div>
                              </div>
                            </TooltipContent>
                          </Tooltip>
                        </TooltipProvider>
                      </td>
                      <td class="px-4 py-3 text-right whitespace-nowrap">
                        <div class="flex justify-end gap-1">
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="previewDocumentTarget = doc"
                          >
                            {{ t('预览') }}
                          </Button>
                          <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8"
                                :aria-label="t('更多操作')"
                              >
                                <MoreHorizontal class="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-36">
                              <DropdownMenuItem
                                v-if="doc.source_type === 'manual'"
                                @select="openManualEditDialog(doc)"
                              >
                                {{ t('编辑') }}
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                @select="openMoveDocumentDialog(doc)"
                              >
                                {{ t('移动分组') }}
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                :disabled="
                                  reindexDocumentForm.processing &&
                                  reindexingDocumentId === doc.id
                                "
                                @select="reindexDocument(doc)"
                              >
                                {{ t('重新索引') }}
                              </DropdownMenuItem>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem
                                class="text-destructive focus:text-destructive"
                                :disabled="deleteDocumentForm.processing"
                                @select="deleteDocumentTarget = doc"
                              >
                                {{ t('删除') }}
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </div>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>

            <div
              v-if="currentListPagination.last_page > 1"
              class="border-t p-3"
            >
              <PaginationNavigator
                :pagination="currentListPagination"
                :page-url="buildDocumentListPageUrl"
              />
            </div>
          </div>
        </div>
      </div>
    </KnowledgeBasesLayout>

    <KnowledgeGroupFormDialog
      :open="groupDialogOpen"
      :mode="groupDialogMode"
      :knowledge-base-id="groupDialogKbId"
      :groups="groupDialogKb?.document_groups ?? []"
      :group="groupDialogTarget"
      @update:open="groupDialogOpen = $event"
    />

    <KnowledgeDocumentPreviewDialog
      v-if="selectedKb"
      :open="previewDocumentTarget !== null"
      :knowledge-base-id="selectedKb.id"
      :document="previewDocumentTarget"
      @update:open="
        previewDocumentTarget = $event ? previewDocumentTarget : null
      "
    />

    <Dialog
      :open="moveDocumentTarget !== null"
      @update:open="moveDocumentTarget = $event ? moveDocumentTarget : null"
    >
      <DialogContent class="sm:max-w-sm">
        <DialogHeader class="space-y-3">
          <DialogTitle>{{ t('移动分组') }}</DialogTitle>
        </DialogHeader>

        <div class="grid gap-2">
          <Label for="move-document-group">{{ t('目标分组') }}</Label>
          <Select v-model="moveDocumentForm.group_id">
            <SelectTrigger id="move-document-group" class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="group in selectedKbGroupOptions"
                :key="group.id"
                :value="group.id"
              >
                {{ group.label }}
              </SelectItem>
            </SelectContent>
          </Select>
          <p
            v-if="moveDocumentForm.errors.group_id"
            class="text-xs text-destructive"
          >
            {{ moveDocumentForm.errors.group_id }}
          </p>
        </div>

        <DialogFooter class="gap-2">
          <Button
            type="button"
            variant="secondary"
            :disabled="moveDocumentForm.processing"
            @click="moveDocumentTarget = null"
          >
            {{ t('取消') }}
          </Button>
          <Button
            type="button"
            :disabled="
              moveDocumentForm.processing || !moveDocumentForm.group_id
            "
            @click="moveDocument"
          >
            {{ t('保存') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog
      :open="moveQaEntryTarget !== null"
      @update:open="moveQaEntryTarget = $event ? moveQaEntryTarget : null"
    >
      <DialogContent class="sm:max-w-sm">
        <DialogHeader class="space-y-3">
          <DialogTitle>{{ t('移动分组') }}</DialogTitle>
        </DialogHeader>

        <div class="grid gap-2">
          <Label for="move-qa-entry-group">{{ t('目标分组') }}</Label>
          <Select v-model="moveQaEntryForm.group_id">
            <SelectTrigger id="move-qa-entry-group" class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="group in selectedKbGroupOptions"
                :key="group.id"
                :value="group.id"
              >
                {{ group.label }}
              </SelectItem>
            </SelectContent>
          </Select>
          <p
            v-if="moveQaEntryForm.errors.group_id"
            class="text-xs text-destructive"
          >
            {{ moveQaEntryForm.errors.group_id }}
          </p>
        </div>

        <DialogFooter class="gap-2">
          <Button
            type="button"
            variant="secondary"
            :disabled="moveQaEntryForm.processing"
            @click="moveQaEntryTarget = null"
          >
            {{ t('取消') }}
          </Button>
          <Button
            type="button"
            :disabled="moveQaEntryForm.processing || !moveQaEntryForm.group_id"
            @click="moveQaEntry"
          >
            {{ t('保存') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="uploadDialogOpen" @update:open="uploadDialogOpen = $event">
      <DialogContent
        class="max-h-[calc(100vh-2rem)] overflow-hidden p-0 sm:max-w-2xl"
      >
        <div
          class="max-h-[calc(100vh-2rem)] overflow-y-auto p-6 [scrollbar-gutter:stable]"
        >
          <DialogHeader class="space-y-3 pr-8">
            <DialogTitle>{{ t('上传文档') }}</DialogTitle>
            <DialogDescription>
              {{
                t(
                  '支持 .md / .markdown / .txt / .pdf / .docx / .html / .htm，单个文件不超过 20MB，单次最多上传 20 个。',
                )
              }}
            </DialogDescription>
          </DialogHeader>

          <KnowledgeDocumentUploadPanel
            v-if="selectedKb"
            :key="`upload-dialog:${selectedKb.id}:${selectedGroupId ?? 'all'}`"
            class="mt-5"
            :knowledge-base-id="selectedKb.id"
            :group-id="selectedGroupId"
            :show-heading="false"
            @cancel="uploadDialogOpen = false"
          />
        </div>
      </DialogContent>
    </Dialog>

    <ConfirmDeleteDialog
      :open="deleteKbId !== null"
      :title="t('确认删除知识库？')"
      :detail-title="deletingKb?.name ?? ''"
      :detail-description="
        t('删除后将永久移除此知识库及其下所有文档和索引数据，不可恢复。')
      "
      :processing="deleteKbForm.processing"
      @update:open="deleteKbId = null"
      @confirm="confirmDeleteKb"
    />

    <ConfirmDeleteDialog
      :open="deleteDocumentTarget !== null"
      :title="t('确认删除该文档？')"
      :detail-title="deleteDocumentTarget?.original_filename ?? ''"
      :detail-description="t('删除后将移除该文档以及它后续生成的索引数据。')"
      :processing="deleteDocumentForm.processing"
      @update:open="deleteDocumentTarget = null"
      @confirm="confirmDeleteDocument"
    />

    <ConfirmDeleteDialog
      :open="deleteQaEntryTarget !== null"
      :title="t('确认删除该问答？')"
      :detail-title="deleteQaEntryTarget?.question ?? ''"
      :detail-description="t('删除后将移除该问答、相似问法和全部答案。')"
      :processing="deleteQaEntryForm.processing"
      @update:open="deleteQaEntryTarget = null"
      @confirm="confirmDeleteQaEntry"
    />
  </AppLayout>
</template>
