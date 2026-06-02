<!--
  文件说明：收件箱入口页面，承接总管理后台的会话收件箱。
-->
<script setup lang="ts">
import ImagePreviewDialog from '@/components/common/ImagePreviewDialog.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
  type AttachmentPurpose,
  resolveAttachmentUploadError,
  useAttachmentUploader,
} from '@/composables/useAttachmentUploader';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import { useInboxAutoTranslate } from '@/composables/useInboxAutoTranslate';
import { useInboxSummaryAutoTranslate } from '@/composables/useInboxSummaryAutoTranslate';
import { useReplyTranslationPreview } from '@/composables/useReplyTranslationPreview';
import { useToast } from '@/composables/useToast';
import { useVisitorDisplay } from '@/composables/useVisitorDisplay';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import { COMPOSER_EMOJIS } from '@/lib/composerEmojis';
import { formatFileSize } from '@/lib/format';
import { getAvatarInitial } from '@/lib/initials';
import { openMercureEventSource, receptionInboxTopic } from '@/lib/mercure';
import CannedReplyPicker from '@/pages/inbox/CannedReplyPicker.vue';
import ConversationSummaryBlock from '@/pages/inbox/ConversationSummaryBlock.vue';
import InboxContextPanel from '@/pages/inbox/InboxContextPanel.vue';
import InboxMessageSearchResults from '@/pages/inbox/InboxMessageSearchResults.vue';
import InboxStitchedTimeline from '@/pages/inbox/InboxStitchedTimeline.vue';
import InboxToolbar from '@/pages/inbox/InboxToolbar.vue';
import {
  default as workspace,
  default as workspaceRoutes,
} from '@/routes/workspace';
import inboxActions from '@/routes/workspace/inbox';
import type { AppPageProps } from '@/types';
import type {
  AiModelOptionData,
  ContactStitchedTimelineData,
  ContactTimelineEntryData,
  ConversationSummaryData,
  InboxMessageSearchResultData,
  InboxReplyPolishCandidateData,
  InboxView,
  ListConversationItemData,
  ShowInboxPagePropsData,
} from '@/types/generated';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Eye,
  EyeOff,
  Image as ImageIcon,
  Languages,
  Loader2,
  MessageSquareQuote,
  Paperclip,
  PencilLine,
  RefreshCw,
  RotateCcw,
  Search,
  SlidersHorizontal,
  Smile,
  Sparkles,
  Star,
  UserRound,
  X,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps<ShowInboxPagePropsData>();

const { t } = useI18n();
const { toast } = useToast();
const { upload } = useAttachmentUploader();
const { formatRelativeShortWithTooltip } = useDateTime();
const { formatVisitorName } = useVisitorDisplay();
const currentWorkspace = useRequiredWorkspace();
const page = usePage<AppPageProps>();
const currentUserId = computed<string | null>(() => {
  const id = page.props.auth.user?.id;
  return id ? String(id) : null;
});
const currentUserLocale = computed(() => page.props.auth.user.locale);
const workspaceUserContext = computed(() => page.props.workspaceUserContext);
const isCurrentUserOffline = computed(
  () => Number(workspaceUserContext.value?.user_online_status?.value) === 0,
);
interface StoredAiModelSelection {
  id: string;
  label: string;
  providerName: string;
  modelId: string;
}

type StringEnumOptionData = {
  value: string;
  label: string;
  description: string | null;
};

const aiAssistantModelStorageKey = computed(
  () => `ai-assistant:selected-model:${currentWorkspace.value.id}`,
);
const aiModelOptions = computed<AiModelOptionData[]>(() => {
  if (!Array.isArray(page.props.aiAssistantLlmModelOptions)) {
    throw new Error('aiAssistantLlmModelOptions is required.');
  }

  return page.props.aiAssistantLlmModelOptions;
});
const groupedAiModelOptions = computed(() => {
  const groups = new Map<string, AiModelOptionData[]>();
  for (const option of aiModelOptions.value) {
    const list = groups.get(option.provider_name) ?? [];
    list.push(option);
    groups.set(option.provider_name, list);
  }

  return Array.from(groups, ([providerName, options]) => ({
    providerName,
    options,
  }));
});
const replyPolishToneStorageKey = computed(
  () => `helmdesk.inbox.reply-polish-tone:${currentWorkspace.value.id}`,
);
const replyPolishToneOptions = computed<StringEnumOptionData[]>(
  () => props.reply_polish_tone_options as StringEnumOptionData[],
);
const replyAssistantModeOptions = computed<StringEnumOptionData[]>(
  () => props.reply_assistant_mode_options as StringEnumOptionData[],
);

const timelineScrollRef = ref<HTMLElement | null>(null);
const replyComposerRef = ref<HTMLTextAreaElement | null>(null);
const replyFileInputRef = ref<HTMLInputElement | null>(null);
const replyImageInputRef = ref<HTMLInputElement | null>(null);
const replyAttachmentUploading = ref(false);
const importanceProcessing = ref(false);
const SHOW_TIMELINE_EVENTS_STORAGE_KEY = 'helmdesk.inbox.show_timeline_events';
const AUTO_TRANSLATE_VISIBLE_STORAGE_KEY = `helmdesk.inbox.auto_translate_visible.${currentWorkspace.value.id}`;
const AUTO_TRANSLATE_REPLY_STORAGE_KEY = `helmdesk.inbox.auto_translate_reply.${currentWorkspace.value.id}`;

function getStoredShowTimelineEvents(): boolean {
  if (typeof window === 'undefined') {
    return true;
  }

  return (
    window.localStorage.getItem(SHOW_TIMELINE_EVENTS_STORAGE_KEY) !== 'false'
  );
}

function getStoredAutoTranslateVisible(): boolean {
  if (typeof window === 'undefined') {
    return false;
  }

  return (
    window.localStorage.getItem(AUTO_TRANSLATE_VISIBLE_STORAGE_KEY) === 'true'
  );
}

function getStoredAutoTranslateReply(): boolean {
  if (typeof window === 'undefined') {
    return false;
  }

  return (
    window.localStorage.getItem(AUTO_TRANSLATE_REPLY_STORAGE_KEY) === 'true'
  );
}

function toggleTimelineEvents(): void {
  showTimelineEvents.value = !showTimelineEvents.value;
}

function toggleAutoTranslateVisibleMessages(): void {
  autoTranslateVisibleMessages.value = !autoTranslateVisibleMessages.value;
}

function toggleReplyAutoTranslate(): void {
  autoTranslateReply.value = !autoTranslateReply.value;
}

const showTimelineEvents = ref(getStoredShowTimelineEvents());
const autoTranslateVisibleMessages = ref(getStoredAutoTranslateVisible());
const autoTranslateReply = ref(getStoredAutoTranslateReply());
const timelineEventsToggleTitle = computed(() =>
  showTimelineEvents.value ? t('隐藏事件消息') : t('显示事件消息'),
);
const autoTranslateVisibleToggleTitle = computed(() =>
  autoTranslateVisibleMessages.value ? t('关闭自动翻译') : t('打开自动翻译'),
);
const replyAutoTranslateToggleTitle = computed(() =>
  autoTranslateReply.value ? t('关闭翻译发送') : t('翻译发送'),
);
const messageSearchActive = ref(false);
const messageSearchQuery = ref('');
const messageSearchLoading = ref(false);
const messageSearchResults = ref<InboxMessageSearchResultData[]>([]);
const messageSearchInputRef = ref<HTMLInputElement | null>(null);
const highlightedTimelineMessageId = ref<string | null>(null);
const stitchedTimeline = ref<ContactStitchedTimelineData | null>(
  props.selection?.stitched_timeline ?? null,
);
const activeStitchedTimeline = computed<ContactStitchedTimelineData | null>(
  () => stitchedTimeline.value,
);
const timelineLoadingPrevious = ref(false);
const timelineLoadingNext = ref(false);
const timelineLoadingAnchor = ref(false);
const timelineAutoLoadPaused = ref(false);
const TIMELINE_PAGE_SIZE = 50;
const TIMELINE_SCROLL_EDGE_PX = 96;
let messageSearchTimer: number | null = null;
let messageSearchController: AbortController | null = null;
let highlightedTimelineMessageTimer: number | null = null;
let timelineAutoLoadResumeTimer: number | null = null;
let timelineAnchorScrollTimer: number | null = null;

interface TimelineWindowQuery {
  before?: string;
  after?: string;
  anchor_type?: 'message';
  anchor_id?: string;
}

interface TimelineWindowResponse {
  timeline: ContactStitchedTimelineData;
}

function abortMessageSearch(): void {
  if (messageSearchTimer) {
    window.clearTimeout(messageSearchTimer);
    messageSearchTimer = null;
  }
  if (messageSearchController) {
    messageSearchController.abort();
    messageSearchController = null;
  }
}

function openMessageSearch(): void {
  messageSearchActive.value = true;
  messageSearchQuery.value = '';
  messageSearchResults.value = [];
  messageSearchLoading.value = false;
  nextTick(() => messageSearchInputRef.value?.focus());
}

function closeMessageSearch(): void {
  abortMessageSearch();
  messageSearchActive.value = false;
  messageSearchQuery.value = '';
  messageSearchResults.value = [];
  messageSearchLoading.value = false;
}

function clearTimelineMessageHighlight(): void {
  if (highlightedTimelineMessageTimer) {
    window.clearTimeout(highlightedTimelineMessageTimer);
    highlightedTimelineMessageTimer = null;
  }
  highlightedTimelineMessageId.value = null;
}

function clearTimelineAnchorScrollTimers(): void {
  if (timelineAutoLoadResumeTimer) {
    window.clearTimeout(timelineAutoLoadResumeTimer);
    timelineAutoLoadResumeTimer = null;
  }

  if (timelineAnchorScrollTimer) {
    window.clearTimeout(timelineAnchorScrollTimer);
    timelineAnchorScrollTimer = null;
  }
}

function findTimelineMessageElement(messageId: string): HTMLElement | null {
  const timeline = timelineScrollRef.value;
  if (!timeline) {
    return null;
  }

  const elements = timeline.querySelectorAll<HTMLElement>(
    '[data-inbox-timeline-message-id]',
  );

  return (
    Array.from(elements).find(
      (element) => element.dataset.inboxTimelineMessageId === messageId,
    ) ?? null
  );
}

async function focusTimelineSearchResult(
  result: InboxMessageSearchResultData,
): Promise<void> {
  timelineLoadingAnchor.value = true;
  try {
    stitchedTimeline.value = await fetchContactTimelineWindow({
      anchor_type: 'message',
      anchor_id: result.id,
    });
  } finally {
    timelineLoadingAnchor.value = false;
  }

  closeMessageSearch();
  highlightedTimelineMessageId.value = result.id;

  await focusTimelineMessage(result.id);

  if (highlightedTimelineMessageTimer) {
    window.clearTimeout(highlightedTimelineMessageTimer);
  }
  highlightedTimelineMessageTimer = window.setTimeout(() => {
    highlightedTimelineMessageId.value = null;
    highlightedTimelineMessageTimer = null;
  }, 2400);
}

function centerTimelineMessage(messageId: string): void {
  const timeline = timelineScrollRef.value;
  const target = findTimelineMessageElement(messageId);

  if (!timeline || !target) {
    return;
  }

  const timelineRect = timeline.getBoundingClientRect();
  const targetRect = target.getBoundingClientRect();
  const targetTop = targetRect.top - timelineRect.top + timeline.scrollTop;
  const nextScrollTop =
    targetTop - (timeline.clientHeight - targetRect.height) / 2;

  timeline.scrollTo({
    top: Math.max(0, nextScrollTop),
    behavior: 'auto',
  });
}

async function focusTimelineMessage(messageId: string): Promise<void> {
  clearTimelineAnchorScrollTimers();
  timelineAutoLoadPaused.value = true;

  await nextTick();

  window.requestAnimationFrame(() => {
    centerTimelineMessage(messageId);
  });

  timelineAnchorScrollTimer = window.setTimeout(() => {
    centerTimelineMessage(messageId);
    timelineAnchorScrollTimer = null;
  }, 120);

  timelineAutoLoadResumeTimer = window.setTimeout(() => {
    timelineAutoLoadPaused.value = false;
    timelineAutoLoadResumeTimer = null;
  }, 700);
}

async function fetchContactTimelineWindow(
  query: TimelineWindowQuery,
): Promise<ContactStitchedTimelineData> {
  const contactId = props.selection?.contact?.id;

  if (!contactId) {
    throw new Error('Missing selected contact for inbox timeline.');
  }

  const response = await fetch(
    inboxActions.contacts.timeline.url(
      {
        contactId,
      },
      { query: { ...query, per_page: TIMELINE_PAGE_SIZE } },
    ),
    {
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    },
  );

  if (!response.ok) {
    throw new Error(`Inbox timeline request failed: ${response.status}`);
  }

  const data = (await response.json()) as TimelineWindowResponse;

  return data.timeline;
}

function timelineEntryKey(entry: ContactTimelineEntryData): string {
  return `${entry.type}:${entry.id}`;
}

function mergeTimelineWindow(
  current: ContactStitchedTimelineData,
  incoming: ContactStitchedTimelineData,
  direction: 'before' | 'after',
): ContactStitchedTimelineData {
  const existingKeys = new Set(current.entries.map(timelineEntryKey));
  const incomingEntries = incoming.entries.filter(
    (entry) => !existingKeys.has(timelineEntryKey(entry)),
  );

  return {
    ...current,
    entries:
      direction === 'before'
        ? [...incomingEntries, ...current.entries]
        : [...current.entries, ...incomingEntries],
    previous_cursor:
      direction === 'before'
        ? incoming.previous_cursor
        : current.previous_cursor,
    next_cursor:
      direction === 'after' ? incoming.next_cursor : current.next_cursor,
    anchor_entry_id: incoming.anchor_entry_id ?? current.anchor_entry_id,
  };
}

async function loadPreviousTimelineEntries(): Promise<void> {
  const timeline = stitchedTimeline.value;

  if (
    timelineLoadingPrevious.value ||
    timelineLoadingNext.value ||
    timelineLoadingAnchor.value ||
    timelineAutoLoadPaused.value ||
    !timeline?.previous_cursor
  ) {
    return;
  }

  const scrollElement = timelineScrollRef.value;
  const previousScrollHeight = scrollElement?.scrollHeight ?? 0;

  timelineLoadingPrevious.value = true;
  try {
    const incoming = await fetchContactTimelineWindow({
      before: timeline.previous_cursor,
    });
    stitchedTimeline.value = mergeTimelineWindow(timeline, incoming, 'before');
  } finally {
    timelineLoadingPrevious.value = false;
  }

  await nextTick();

  if (scrollElement) {
    scrollElement.scrollTop +=
      scrollElement.scrollHeight - previousScrollHeight;
  }
}

async function loadNextTimelineEntries(): Promise<void> {
  const timeline = stitchedTimeline.value;

  if (
    timelineLoadingPrevious.value ||
    timelineLoadingNext.value ||
    timelineLoadingAnchor.value ||
    timelineAutoLoadPaused.value ||
    !timeline?.next_cursor
  ) {
    return;
  }

  timelineLoadingNext.value = true;
  try {
    const incoming = await fetchContactTimelineWindow({
      after: timeline.next_cursor,
    });
    stitchedTimeline.value = mergeTimelineWindow(timeline, incoming, 'after');
  } finally {
    timelineLoadingNext.value = false;
  }
}

function handleTimelineScroll(): void {
  const scrollElement = timelineScrollRef.value;

  if (!scrollElement) {
    return;
  }

  if (timelineAutoLoadPaused.value) {
    return;
  }

  if (scrollElement.scrollTop <= TIMELINE_SCROLL_EDGE_PX) {
    void loadPreviousTimelineEntries();
  }

  const bottomDistance =
    scrollElement.scrollHeight -
    scrollElement.scrollTop -
    scrollElement.clientHeight;

  if (bottomDistance <= TIMELINE_SCROLL_EDGE_PX) {
    void loadNextTimelineEntries();
  }
}

function handleMessageSearchInput(): void {
  abortMessageSearch();

  const query = messageSearchQuery.value.trim();
  if (!query) {
    messageSearchResults.value = [];
    messageSearchLoading.value = false;
    return;
  }

  messageSearchLoading.value = true;
  messageSearchTimer = window.setTimeout(() => {
    messageSearchTimer = null;
    executeMessageSearch(query);
  }, 300);
}

async function executeMessageSearch(query: string): Promise<void> {
  const contactId = props.selection?.contact?.id;
  if (!contactId) {
    messageSearchLoading.value = false;
    messageSearchResults.value = [];
    return;
  }

  const controller = new AbortController();
  messageSearchController = controller;

  try {
    const response = await fetch(
      inboxActions.contacts.messages.search.url(
        {
          contactId,
        },
        { query: { search: query } },
      ),
      {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        signal: controller.signal,
      },
    );

    if (!response.ok) {
      messageSearchResults.value = [];
      return;
    }

    const data = (await response.json()) as {
      results: InboxMessageSearchResultData[];
    };
    messageSearchResults.value = data.results;
  } catch (error) {
    if ((error as DOMException)?.name === 'AbortError') return;
    messageSearchResults.value = [];
  } finally {
    if (messageSearchController === controller) {
      messageSearchController = null;
      messageSearchLoading.value = false;
    }
  }
}

const emojiPopoverOpen = ref(false);
const cannedReplyPickerOpen = ref(false);
const cannedReplyPickerQuery = ref('');
// 当用户在 textarea 中输入 `/keyword`（行首）时记录的范围，用于回填时把 `/keyword` 替换成模版内容。
const cannedReplyTriggerRange = ref<{ start: number; end: number } | null>(
  null,
);
const cannedReplyPickerRef = ref<InstanceType<typeof CannedReplyPicker> | null>(
  null,
);
const replyPolishOpen = ref(false);
const replyPolishSettingsOpen = ref(false);
const replyPolishSelectedMode = ref('');
const replyPolishSelectedModelId = ref('');
const replyPolishSelectedTone = ref('');
const replyPolishCandidates = ref<InboxReplyPolishCandidateData[]>([]);
const replyPolishCandidateCache = ref<
  Record<string, InboxReplyPolishCandidateData[]>
>({});
const replyPolishCandidateCacheKeys = ref<string[]>([]);
const replyPolishSignature = ref('');
const replyPolishLoading = ref(false);
const replyPolishError = ref<string | null>(null);
const contextPanelWidth = ref(380);
const contextPanelCollapsed = ref(false);
const isResizingContextPanel = ref(false);
const CONTEXT_PANEL_MIN_WIDTH = 320;
const CONTEXT_PANEL_MAX_WIDTH = 640;
const MAX_REPLY_ATTACHMENT_COUNT = 10;
const REPLY_POLISH_DEBOUNCE_MS = 600;
const REPLY_POLISH_CACHE_LIMIT = 20;
type PendingReplyAttachmentStatus = 'uploading' | 'uploaded' | 'failed';

interface PendingReplyAttachment {
  id: string;
  name: string;
  byteSize: number;
  previewUrl: string | null;
  progress: number;
  status: PendingReplyAttachmentStatus;
  statusLabel: string | null;
}

interface PendingReplyUpload {
  id: string;
  conversationId: string;
  kind: 'file' | 'image';
  attachments: PendingReplyAttachment[];
}

interface ReplyQuoteTarget {
  id: string;
  senderName: string;
  preview: string;
  content: string | null;
  attachments: ReplyQuoteAttachment[];
}

interface ReplyQuoteAttachment {
  id: string;
  name: string;
  mime_type: string;
  byte_size: number;
  url: string;
  preview_url?: string | null;
}

const pendingReplyUploads = ref<PendingReplyUpload[]>([]);
const replyQuote = ref<ReplyQuoteTarget | null>(null);
const replyQuotePreviewOpen = ref(false);
const replyQuotePreviewImages = ref<ReplyQuoteAttachment[]>([]);
const replyQuotePreviewInitialId = ref<string | null>(null);
const replyQuoteTextDialogOpen = ref(false);
const replyQuoteDialogTitle = ref('');
const replyQuoteDialogContent = ref('');
const locallyReadConversationIds = ref<Set<string>>(new Set());
const updatingOnlineStatus = ref(false);
let inboxEventSource: EventSource | null = null;
let inboxReloadTimer: number | null = null;
let pendingReplyUploadSequence = 0;
let replyPolishRequestSequence = 0;
let replyPolishTimer: number | null = null;
let replyPolishController: AbortController | null = null;

function preloadImage(url: string): Promise<void> {
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = () => resolve();
    img.onerror = () => resolve();
    img.src = url;
  });
}

const contextPanelStyle = computed(() => ({
  width: `${contextPanelWidth.value}px`,
}));

const CONTEXT_PANEL_TOGGLE_WIDTH_PX = 16;

const contextPanelToggleClass = computed(() =>
  contextPanelCollapsed.value
    ? 'rounded-l-md border-r-0'
    : 'rounded-r-md border-l-0',
);

function clampContextPanelWidth(width: number): number {
  return Math.min(
    CONTEXT_PANEL_MAX_WIDTH,
    Math.max(CONTEXT_PANEL_MIN_WIDTH, width),
  );
}

function stopResizeContextPanel(): void {
  isResizingContextPanel.value = false;
  window.removeEventListener('pointermove', resizeContextPanel);
  window.removeEventListener('pointerup', stopResizeContextPanel);
  window.removeEventListener('pointercancel', stopResizeContextPanel);
}

function resizeContextPanel(event: PointerEvent): void {
  contextPanelWidth.value = clampContextPanelWidth(
    window.innerWidth - event.clientX - CONTEXT_PANEL_TOGGLE_WIDTH_PX,
  );
}

function startResizeContextPanel(event: PointerEvent): void {
  if (contextPanelCollapsed.value) return;

  event.preventDefault();
  isResizingContextPanel.value = true;
  window.addEventListener('pointermove', resizeContextPanel);
  window.addEventListener('pointerup', stopResizeContextPanel);
  window.addEventListener('pointercancel', stopResizeContextPanel);
}

function toggleContextPanel(): void {
  contextPanelCollapsed.value = !contextPanelCollapsed.value;
}

onUnmounted(() => {
  stopResizeContextPanel();
  clearTimelineMessageHighlight();
  clearTimelineAnchorScrollTimers();
  closeInboxEventSource();
  clearPendingReplyUploads();
  clearInboxReloadTimers();
  cancelReplyPolishRequest();
});

function closeInboxEventSource(): void {
  if (inboxEventSource) {
    inboxEventSource.close();
    inboxEventSource = null;
  }
}

/**
 * 当前会话事件需要同时刷新右侧时间线；非当前会话事件保持选中不被换走。
 * 两种刷新都不重拉 current_conversation_id —— 选中态由用户的显式导航决定，
 * 避免 URL 未带 conversation_id 时分部刷新把当前会话偷换成列表新置顶项。
 */
const INBOX_RELOAD_DEBOUNCE_MS = 300;
const INBOX_RELOAD_MAX_WAIT_MS = 2000;
let inboxReloadMaxWaitTimer: number | null = null;

function reloadInboxListAndCounts(): void {
  router.reload({
    only: ['conversation_list', 'tab_counts'],
  });
}

function reloadInboxWithSelection(): void {
  router.reload({
    only: ['conversation_list', 'selection', 'tab_counts'],
  });
}

function clearInboxReloadTimers(): void {
  if (inboxReloadTimer !== null) {
    window.clearTimeout(inboxReloadTimer);
    inboxReloadTimer = null;
  }
  if (inboxReloadMaxWaitTimer !== null) {
    window.clearTimeout(inboxReloadMaxWaitTimer);
    inboxReloadMaxWaitTimer = null;
  }
}

function flushInboxReload(): void {
  clearInboxReloadTimers();
  reloadInboxWithSelection();
}

/**
 * SSE 高频事件下使用 debounce + max-wait，避免被新事件无限延后导致 badge 不刷新。
 */
function scheduleInboxListReload(): void {
  if (inboxReloadTimer !== null) {
    window.clearTimeout(inboxReloadTimer);
  }
  inboxReloadTimer = window.setTimeout(() => {
    clearInboxReloadTimers();
    reloadInboxListAndCounts();
  }, INBOX_RELOAD_DEBOUNCE_MS);
  if (inboxReloadMaxWaitTimer === null) {
    inboxReloadMaxWaitTimer = window.setTimeout(() => {
      clearInboxReloadTimers();
      reloadInboxListAndCounts();
    }, INBOX_RELOAD_MAX_WAIT_MS);
  }
}

function subscribeInboxRealtime(): void {
  closeInboxEventSource();

  const source = openMercureEventSource(receptionInboxTopic());
  inboxEventSource = source;

  source.addEventListener('reception', (event) => {
    let isCurrentConversation = false;
    let isCurrentContact = false;
    let eventConversationId: string | null = null;

    try {
      const payload = JSON.parse((event as MessageEvent).data) as {
        conversation_id?: string;
        contact_id?: string;
      };

      eventConversationId = payload.conversation_id ?? null;
      isCurrentConversation =
        !!eventConversationId &&
        eventConversationId === props.current_conversation_id;
      isCurrentContact =
        !!payload.contact_id &&
        payload.contact_id === props.selection?.contact?.id;
    } catch {
      // payload 解析失败时仍走 schedule；topic 已经限定了事件范围。
    }

    if (isCurrentConversation) {
      void markConversationRead(eventConversationId ?? '').finally(() => {
        flushInboxReload();
      });
      return;
    }

    if (isCurrentContact) {
      flushInboxReload();
      return;
    }

    scheduleInboxListReload();
  });
}

onMounted(() => {
  subscribeInboxRealtime();
});

// 切换 tab / 筛选时 Inertia 会保留 Inbox 实例，仅刷新 props；
// immediate: true 让首次渲染也走「打开即已读」，避免和 onMounted 各写一遍。
watch(
  () => props.current_conversation_id,
  () => {
    closeMessageSearch();
    clearTimelineMessageHighlight();
    clearTimelineAnchorScrollTimers();
    timelineAutoLoadPaused.value = false;
    void markCurrentConversationRead();
  },
  { immediate: true },
);

function buildInboxUrl(
  overrides: Record<string, string | null | undefined>,
): string {
  const query: Record<string, string> = {};
  if (props.current_view) query.view = props.current_view;
  if (props.current_channel_id) query.channel = props.current_channel_id;
  if (props.current_assignee) query.assignee = props.current_assignee;
  if (props.current_search) query.search = props.current_search;
  if (props.current_important_only) query.important = '1';
  if (props.current_conversation_id) {
    query.conversation_id = props.current_conversation_id;
  }
  for (const [key, value] of Object.entries(overrides)) {
    if (value === null) {
      delete query[key];
    } else if (value !== undefined) {
      query[key] = value;
    }
  }
  return workspaceRoutes.inbox.show.url({ query });
}

function displayedUnreadCount(conversation: ListConversationItemData): number {
  return locallyReadConversationIds.value.has(conversation.id)
    ? 0
    : conversation.unread_count;
}

function rememberConversationRead(conversationId: string): void {
  locallyReadConversationIds.value = new Set([
    ...locallyReadConversationIds.value,
    conversationId,
  ]);
}

async function markConversationRead(
  conversationId: string,
  options: { reload?: boolean } = {},
): Promise<void> {
  if (!conversationId) return;

  rememberConversationRead(conversationId);

  try {
    await axios.post(
      inboxActions.conversations.read.url({
        conversation: conversationId,
      }),
    );

    if (options.reload) {
      router.reload({
        only: ['conversation_list', 'tab_counts'],
      });
    }
  } catch {
    // 已读标记失败交给全局 axios interceptor 提示。
  }
}

async function markCurrentConversationRead(): Promise<void> {
  const conversationId = props.current_conversation_id;
  if (!conversationId) return;

  const conversation = props.conversation_list.find(
    (item) => item.id === conversationId,
  );
  if (conversation && displayedUnreadCount(conversation) === 0) return;

  await markConversationRead(conversationId, { reload: true });
}

async function selectConversation(
  conversation: ListConversationItemData,
): Promise<void> {
  if (displayedUnreadCount(conversation) > 0) {
    await markConversationRead(conversation.id);
  }

  router.get(
    buildInboxUrl({ conversation_id: conversation.id }),
    {},
    { preserveScroll: false, preserveState: false },
  );
}

const replyForm = useForm({
  content: '',
  attachment_ids: [] as string[],
  quoted_message_id: null as string | null,
  visitor_content: null as string | null,
  visitor_locale: null as string | null,
  source_locale: null as string | null,
});

const selectionComputed = computed(() => props.selection ?? null);
const replyContentRef = computed({
  get: () => replyForm.content,
  set: (value: string) => {
    replyForm.content = value;
  },
});

const {
  autoTranslatingMessageIds,
  scheduleObserverRefresh: scheduleAutoTranslateObserverRefresh,
  stopObserverAndTimers: stopAutoTranslateObserverAndTimers,
} = useInboxAutoTranslate({
  selection: selectionComputed,
  currentUserId,
  currentUserLocale,
  activeStitchedTimeline,
  timelineScrollRef,
  enabled: autoTranslateVisibleMessages,
});

const {
  autoTranslatingSummaryIds,
  scheduleObserverRefresh: scheduleSummaryAutoTranslateObserverRefresh,
  stopObserverAndTimers: stopSummaryAutoTranslateObserverAndTimers,
} = useInboxSummaryAutoTranslate({
  selection: selectionComputed,
  currentUserLocale,
  activeStitchedTimeline,
  timelineScrollRef,
  enabled: autoTranslateVisibleMessages,
});

const replyTranslation = useReplyTranslationPreview({
  selection: selectionComputed,
  currentUserLocale,
  replyContent: replyContentRef,
  enabled: autoTranslateReply,
});

// 模板绑定别名：保持模板引用与提取前一致
const replyTranslationDraft = replyTranslation.draft;
const replyTranslationLoading = replyTranslation.loading;
const replyTranslationTouched = replyTranslation.touched;
const replyTranslationError = replyTranslation.error;
const replyExpectedVisitorLocale = replyTranslation.expectedVisitorLocale;
const replyTranslationRequirementMessage = replyTranslation.requirementMessage;
const showReplyTranslationPreview = replyTranslation.showPreview;
const replyTranslationTitle = replyTranslation.title;
// 译文草稿变化时同步到回复表单的翻译字段
watch(replyTranslationDraft, () => {
  replyTranslation.applyToForm(replyForm);
});

watch(
  [aiModelOptions, aiAssistantModelStorageKey],
  () => syncReplyPolishModelSelection(),
  { immediate: true },
);

watch(
  [replyPolishToneOptions, replyPolishToneStorageKey],
  () => syncReplyPolishToneSelection(),
  { immediate: true },
);

watch(replyAssistantModeOptions, () => syncReplyAssistantModeSelection(), {
  immediate: true,
});

watch(replyPolishSelectedMode, () => {
  scheduleReplyPolish();
});

watch(replyPolishSelectedModelId, (value) => {
  if (value.trim() === '') {
    clearStoredAiModelSelection();
    scheduleReplyPolish();
    return;
  }

  storeReplyPolishModelSelection(value);
  scheduleReplyPolish();
});

watch(replyPolishSelectedTone, (value) => {
  if (value.trim() === '') {
    clearStoredReplyPolishTone();
    scheduleReplyPolish();
    return;
  }

  storeReplyPolishToneSelection(value);
  scheduleReplyPolish();
});

watch(replyPolishOpen, (open) => {
  if (open) {
    selectDefaultReplyAssistantMode();
    replyPolishError.value = null;
    scheduleReplyPolish();
    return;
  }

  replyPolishSettingsOpen.value = false;
  cancelReplyPolishRequest();
});

const transferForm = useForm({
  target_user_id: '',
});

const transferTeammates = computed(() =>
  props.teammates.filter((teammate) => teammate.id !== currentUserId.value),
);

const canTransferToTeammate = computed(
  () =>
    !!props.selection?.can_transfer_to_teammate &&
    transferTeammates.value.length > 0,
);

const selectedContactImportant = computed(
  () => props.selection?.contact?.is_important === true,
);

const importanceToggleTitle = computed(() =>
  selectedContactImportant.value ? t('取消重点客户') : t('标为重点客户'),
);

const isAiOwnedSelection = computed(() => {
  const conversation = props.selection?.conversation;

  return (
    conversation?.status === 'open' &&
    conversation?.assigned_user_id === null &&
    conversation.inbox_status === 'ai_handling'
  );
});

const isReplyActionDisabled = computed(
  () =>
    !props.selection?.can_reply ||
    replyAttachmentUploading.value ||
    replyForm.processing,
);

const canSubmitReply = computed(
  () =>
    !!props.selection?.can_reply &&
    !replyForm.processing &&
    !replyAttachmentUploading.value &&
    replyForm.content.trim().length > 0 &&
    replyTranslation.ready.value,
);

const visiblePendingReplyUploads = computed(() => {
  const conversationId = props.selection?.conversation.id;

  return conversationId
    ? pendingReplyUploads.value.filter(
        (upload) => upload.conversationId === conversationId,
      )
    : [];
});

const replyAttachmentError = computed(() => replyForm.errors.attachment_ids);
const hasAvailableReplyPolishModels = computed(
  () => aiModelOptions.value.length > 0,
);
const hasSelectedReplyPolishModel = computed(
  () => replyPolishSelectedModelId.value.trim() !== '',
);
const hasSelectedReplyPolishTone = computed(() =>
  replyPolishToneOptions.value.some(
    (option) => option.value === replyPolishSelectedTone.value,
  ),
);
const hasSelectedReplyAssistantMode = computed(() =>
  replyAssistantModeOptions.value.some(
    (option) => option.value === replyPolishSelectedMode.value,
  ),
);
const canUseReplyPolish = computed(
  () =>
    !isReplyActionDisabled.value &&
    hasSelectedReplyPolishModel.value &&
    hasSelectedReplyAssistantMode.value &&
    hasSelectedReplyPolishTone.value,
);
const replyPolishButtonTitle = computed(() => {
  if (!props.selection?.can_reply) {
    return t('当前会话不可回复');
  }
  if (!hasAvailableReplyPolishModels.value) {
    return t('请先配置可用 AI 模型');
  }
  if (!hasSelectedReplyPolishModel.value) {
    return t('请选择模型后再润色');
  }
  if (!hasSelectedReplyAssistantMode.value) {
    return t('请选择助手模式');
  }
  if (!hasSelectedReplyPolishTone.value) {
    return t('请选择语气');
  }

  return t('AI 回复助手');
});

function submitReply(): void {
  const conversation = props.selection?.conversation;
  if (!conversation) return;
  if (!replyForm.content.trim()) return;

  const conversationId = conversation.id;
  replyTranslation.applyToForm(replyForm);

  replyForm
    .transform((data) => ({
      content: data.content.trim(),
      attachment_ids: [],
      quoted_message_id: replyQuote.value?.id ?? data.quoted_message_id,
      visitor_content: data.visitor_content,
      visitor_locale: data.visitor_locale,
      source_locale: data.source_locale,
    }))
    .post(
      inboxActions.conversations.reply.url({
        conversation: conversationId,
      }),
      {
        preserveScroll: true,
        preserveState: true,
        // 仅拉当前会话的 timeline 与 tab 计数；
        // conversation_list 等保持客户端旧值，避免列表里其他会话的附件 URL 被
        // 反复刷出新签名进而触发浏览器重新拉取图片。
        only: ['selection', 'tab_counts'],
        onSuccess: () => {
          replyForm.reset();
          clearReplyQuote();
          replyTranslation.clear(replyForm);
        },
        onFinish: () => {
          focusReplyComposer(conversationId);
        },
      },
    );
}

function parseStoredAiModelSelection(
  raw: string | null,
): StoredAiModelSelection | null {
  if (!raw) {
    return null;
  }

  const parsed = JSON.parse(raw) as Partial<StoredAiModelSelection>;
  if (
    typeof parsed.id === 'string' &&
    typeof parsed.label === 'string' &&
    typeof parsed.providerName === 'string' &&
    typeof parsed.modelId === 'string'
  ) {
    return {
      id: parsed.id,
      label: parsed.label,
      providerName: parsed.providerName,
      modelId: parsed.modelId,
    };
  }

  throw new Error('Stored AI model selection is invalid.');
}

function clearStoredAiModelSelection(): void {
  if (typeof window === 'undefined') return;

  window.localStorage.removeItem(aiAssistantModelStorageKey.value);
}

function loadStoredAiModelSelection(): StoredAiModelSelection | null {
  if (typeof window === 'undefined') {
    return null;
  }

  try {
    return parseStoredAiModelSelection(
      window.localStorage.getItem(aiAssistantModelStorageKey.value),
    );
  } catch {
    clearStoredAiModelSelection();

    return null;
  }
}

function storeReplyPolishModelSelection(modelId: string): void {
  if (typeof window === 'undefined') return;

  const option = aiModelOptions.value.find((item) => item.value === modelId);
  if (!option) {
    clearStoredAiModelSelection();
    return;
  }

  const payload: StoredAiModelSelection = {
    id: option.value,
    label: option.label,
    providerName: option.provider_name,
    modelId: option.model_id,
  };

  window.localStorage.setItem(
    aiAssistantModelStorageKey.value,
    JSON.stringify(payload),
  );
}

function selectFirstReplyPolishModel(): void {
  const firstOption = aiModelOptions.value[0];
  if (!firstOption) {
    replyPolishSelectedModelId.value = '';
    return;
  }

  replyPolishSelectedModelId.value = firstOption.value;
}

function syncReplyPolishModelSelection(): void {
  const current = replyPolishSelectedModelId.value.trim();
  if (
    current !== '' &&
    aiModelOptions.value.some((option) => option.value === current)
  ) {
    return;
  }

  const remembered = loadStoredAiModelSelection();
  if (remembered) {
    const matched = aiModelOptions.value.find(
      (option) => option.value === remembered.id,
    );
    if (matched) {
      replyPolishSelectedModelId.value = matched.value;
      return;
    }
    clearStoredAiModelSelection();
  }

  selectFirstReplyPolishModel();
}

function clearStoredReplyPolishTone(): void {
  if (typeof window === 'undefined') return;

  window.localStorage.removeItem(replyPolishToneStorageKey.value);
}

function loadStoredReplyPolishTone(): string | null {
  if (typeof window === 'undefined') {
    return null;
  }

  const value = window.localStorage.getItem(replyPolishToneStorageKey.value);
  return value && value.trim() !== '' ? value : null;
}

function storeReplyPolishToneSelection(tone: string): void {
  if (typeof window === 'undefined') return;

  if (!replyPolishToneOptions.value.some((option) => option.value === tone)) {
    clearStoredReplyPolishTone();
    return;
  }

  window.localStorage.setItem(replyPolishToneStorageKey.value, tone);
}

function selectDefaultReplyPolishTone(): void {
  replyPolishSelectedTone.value = replyPolishToneOptions.value[0]?.value ?? '';
}

function syncReplyPolishToneSelection(): void {
  const current = replyPolishSelectedTone.value.trim();
  if (
    current !== '' &&
    replyPolishToneOptions.value.some((option) => option.value === current)
  ) {
    return;
  }

  const remembered = loadStoredReplyPolishTone();
  if (
    remembered &&
    replyPolishToneOptions.value.some((option) => option.value === remembered)
  ) {
    replyPolishSelectedTone.value = remembered;
    return;
  }

  clearStoredReplyPolishTone();
  selectDefaultReplyPolishTone();
}

function selectDefaultReplyAssistantMode(): void {
  replyPolishSelectedMode.value =
    replyAssistantModeOptions.value[0]?.value ?? '';
}

function syncReplyAssistantModeSelection(): void {
  const current = replyPolishSelectedMode.value.trim();
  if (
    current !== '' &&
    replyAssistantModeOptions.value.some((option) => option.value === current)
  ) {
    return;
  }

  selectDefaultReplyAssistantMode();
}

function resetReplyPolishPreview(options: { clearCache?: boolean } = {}): void {
  cancelReplyPolishRequest();
  replyPolishCandidates.value = [];
  replyPolishSignature.value = '';
  replyPolishLoading.value = false;
  replyPolishError.value = null;

  if (options.clearCache === true) {
    replyPolishCandidateCache.value = {};
    replyPolishCandidateCacheKeys.value = [];
  }
}

function cancelReplyPolishRequest(): void {
  if (replyPolishTimer !== null) {
    window.clearTimeout(replyPolishTimer);
    replyPolishTimer = null;
  }

  replyPolishController?.abort();
  replyPolishController = null;
  replyPolishRequestSequence += 1;
  replyPolishLoading.value = false;
}

function resolveReplyPolishError(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined;
    if (data?.errors?.content?.[0]) {
      return data.errors.content[0];
    }
    if (data?.errors?.model_id?.[0]) {
      return data.errors.model_id[0];
    }
    if (data?.errors?.tone?.[0]) {
      return data.errors.tone[0];
    }
    if (typeof data?.message === 'string' && data.message) {
      return data.message;
    }
  }

  return error instanceof Error ? error.message : t('AI 回复助手失败');
}

function buildReplyPolishSignature(
  conversationId: string,
  mode: string,
  source: string,
  modelId: string,
  tone: string,
  quotedMessageId: string | null,
): string {
  return JSON.stringify([
    conversationId,
    mode,
    source,
    modelId,
    tone,
    quotedMessageId,
  ]);
}

function cloneReplyPolishCandidates(
  candidates: InboxReplyPolishCandidateData[],
): InboxReplyPolishCandidateData[] {
  return candidates.map((candidate) => ({ ...candidate }));
}

function rememberReplyPolishCandidates(
  signature: string,
  candidates: InboxReplyPolishCandidateData[],
): void {
  const nextCache = {
    ...replyPolishCandidateCache.value,
    [signature]: cloneReplyPolishCandidates(candidates),
  };
  const nextKeys = replyPolishCandidateCacheKeys.value.filter(
    (key) => key !== signature,
  );

  nextKeys.push(signature);
  while (nextKeys.length > REPLY_POLISH_CACHE_LIMIT) {
    const expiredKey = nextKeys.shift();
    if (expiredKey) {
      delete nextCache[expiredKey];
    }
  }

  replyPolishCandidateCache.value = nextCache;
  replyPolishCandidateCacheKeys.value = nextKeys;
}

function scheduleReplyPolish(force = false): void {
  if (replyPolishTimer !== null) {
    window.clearTimeout(replyPolishTimer);
    replyPolishTimer = null;
  }

  const conversation = props.selection?.conversation;
  const mode = replyPolishSelectedMode.value.trim();
  const source = replyForm.content.trim();
  const modelId = replyPolishSelectedModelId.value.trim();
  const tone = replyPolishSelectedTone.value.trim();
  const quotedMessageId = replyQuote.value?.id ?? null;

  if (
    !replyPolishOpen.value ||
    !conversation ||
    !mode ||
    !modelId ||
    !hasSelectedReplyAssistantMode.value ||
    !hasSelectedReplyPolishTone.value
  ) {
    if (replyPolishOpen.value) {
      resetReplyPolishPreview();
    } else {
      cancelReplyPolishRequest();
    }

    return;
  }

  if (mode === 'rewrite' && source === '') {
    resetReplyPolishPreview();
    replyPolishError.value = t('请输入回复内容后再改写');
    return;
  }

  const signature = buildReplyPolishSignature(
    conversation.id,
    mode,
    source,
    modelId,
    tone,
    quotedMessageId,
  );

  if (
    !force &&
    signature === replyPolishSignature.value &&
    replyPolishCandidates.value.length > 0 &&
    replyPolishError.value === null
  ) {
    return;
  }

  const cachedCandidates = replyPolishCandidateCache.value[signature];
  if (!force && cachedCandidates && cachedCandidates.length > 0) {
    cancelReplyPolishRequest();
    replyPolishSignature.value = signature;
    replyPolishCandidates.value = cloneReplyPolishCandidates(cachedCandidates);
    replyPolishCandidateCacheKeys.value = [
      ...replyPolishCandidateCacheKeys.value.filter((key) => key !== signature),
      signature,
    ];
    replyPolishError.value = null;

    return;
  }

  cancelReplyPolishRequest();
  replyPolishSignature.value = signature;
  replyPolishCandidates.value = [];
  replyPolishError.value = null;
  replyPolishLoading.value = true;

  replyPolishTimer = window.setTimeout(() => {
    replyPolishTimer = null;
    void requestReplyPolish(
      conversation.id,
      mode,
      source,
      modelId,
      tone,
      quotedMessageId,
      signature,
    );
  }, REPLY_POLISH_DEBOUNCE_MS);
}

async function requestReplyPolish(
  conversationId: string,
  mode: string,
  source: string,
  modelId: string,
  tone: string,
  quotedMessageId: string | null,
  signature: string,
): Promise<void> {
  replyPolishController?.abort();
  const controller = new AbortController();
  replyPolishController = controller;
  const requestId = ++replyPolishRequestSequence;
  replyPolishError.value = null;
  replyPolishLoading.value = true;

  try {
    const response = await axios.post<{
      candidates: InboxReplyPolishCandidateData[];
    }>(
      inboxActions.conversations.reply.polish.url({
        conversation: conversationId,
      }),
      {
        mode,
        content: source,
        model_id: modelId,
        tone,
        quoted_message_id: quotedMessageId,
      },
      { signal: controller.signal },
    );

    if (
      controller.signal.aborted ||
      requestId !== replyPolishRequestSequence ||
      props.selection?.conversation.id !== conversationId ||
      replyForm.content.trim() !== source ||
      replyPolishSelectedMode.value !== mode ||
      replyPolishSignature.value !== signature
    ) {
      return;
    }

    const candidates = response.data.candidates.filter(
      (candidate) => candidate.content.trim() !== '',
    );
    replyPolishCandidates.value = candidates;
    if (candidates.length > 0) {
      rememberReplyPolishCandidates(signature, candidates);
    }

    if (replyPolishCandidates.value.length === 0) {
      replyPolishError.value = t('AI 回复助手失败');
    }
  } catch (error) {
    if (controller.signal.aborted || requestId !== replyPolishRequestSequence) {
      return;
    }

    replyPolishCandidates.value = [];
    replyPolishError.value = resolveReplyPolishError(error);
  } finally {
    if (replyPolishController === controller) {
      replyPolishController = null;
    }

    if (requestId === replyPolishRequestSequence) {
      replyPolishLoading.value = false;
    }
  }
}

function refreshReplyPolishCandidates(): void {
  scheduleReplyPolish(true);
}

async function applyReplyPolishCandidate(content: string): Promise<void> {
  const candidate = content.trim();
  if (!candidate) return;

  replyPolishOpen.value = false;
  replyForm.content = candidate;
  resetReplyPolishPreview({ clearCache: true });

  await nextTick();
  replyComposerRef.value?.focus({ preventScroll: true });
}

async function handleReplyFileChange(event: Event): Promise<void> {
  const target = event.target as HTMLInputElement;
  const files = Array.from(target.files ?? []);
  target.value = '';

  await uploadAndSendReplyAttachments(files, 'conversation_file', 'file');
}

async function handleReplyImageChange(event: Event): Promise<void> {
  const target = event.target as HTMLInputElement;
  const files = Array.from(target.files ?? []);
  target.value = '';

  await uploadAndSendReplyAttachments(files, 'conversation_image', 'image');
}

function validateReplyAttachmentFiles(files: File[]): boolean {
  if (files.length === 0) {
    return false;
  }

  if (files.length > MAX_REPLY_ATTACHMENT_COUNT) {
    replyForm.setError(
      'attachment_ids',
      t('一次最多发送 {count} 个附件', {
        count: MAX_REPLY_ATTACHMENT_COUNT,
      }),
    );
    return false;
  }

  return true;
}

async function uploadAndSendReplyAttachments(
  files: File[],
  purpose: AttachmentPurpose,
  kind: 'file' | 'image',
): Promise<void> {
  const conversation = props.selection?.conversation;
  if (!conversation || !props.selection?.can_reply) return;
  if (!validateReplyAttachmentFiles(files)) return;

  const pendingUpload = createPendingReplyUpload(conversation.id, files, kind);
  pendingReplyUploads.value.push(pendingUpload);

  replyAttachmentUploading.value = true;
  replyForm.clearErrors('attachment_ids');
  void scrollTimelineToBottom();

  try {
    const uploadedAttachmentIds: string[] = [];
    const preloadTasks: Promise<void>[] = [];

    for (const [index, file] of files.entries()) {
      const pendingAttachment = pendingUpload.attachments[index];
      const attachment = await upload(file, {
        purpose,
        onProgress: (value) => {
          pendingAttachment.progress = Math.min(100, Math.max(0, value));
        },
      });

      pendingAttachment.name = attachment.name;
      pendingAttachment.byteSize = attachment.byte_size;
      pendingAttachment.progress = 100;
      pendingAttachment.status = 'uploaded';
      uploadedAttachmentIds.push(attachment.id);

      const serverUrl = attachment.preview_url || attachment.full_url;
      if (serverUrl && kind === 'image') {
        preloadTasks.push(preloadImage(serverUrl));
      }
    }

    await Promise.all(preloadTasks);

    replyAttachmentUploading.value = false;
    await sendReplyAttachments(
      conversation.id,
      uploadedAttachmentIds,
      pendingUpload.id,
    );
  } catch (error) {
    replyForm.setError(
      'attachment_ids',
      resolveAttachmentUploadError(
        error,
        t,
        kind === 'image' ? '图片上传失败' : '附件上传失败',
      ),
    );
    markPendingReplyUploadFailed(pendingUpload.id, t('上传失败'));
  } finally {
    replyAttachmentUploading.value = false;
  }
}

function sendReplyAttachments(
  conversationId: string,
  attachmentIds: string[],
  pendingUploadId: string,
): Promise<void> {
  return new Promise((resolve) => {
    replyForm
      .transform(() => ({
        content: '',
        attachment_ids: attachmentIds,
        quoted_message_id: replyQuote.value?.id ?? null,
      }))
      .post(
        inboxActions.conversations.reply.url({
          conversation: conversationId,
        }),
        {
          preserveScroll: true,
          preserveState: true,
          // 仅拉当前会话的 timeline 与 tab 计数；conversation_list 不刷新，
          // 客户端 page store 保持原 attachment URL，浏览器不会触发已渲染图片的重新加载。
          only: ['selection', 'tab_counts'],
          onSuccess: () => {
            removePendingReplyUpload(pendingUploadId);
            clearReplyQuote();
          },
          onError: () => {
            markPendingReplyUploadFailed(pendingUploadId, t('发送失败'), true);
          },
          onFinish: () => {
            void focusReplyComposer(conversationId);
            resolve();
          },
        },
      );
  });
}

function createPendingReplyUpload(
  conversationId: string,
  files: File[],
  kind: 'file' | 'image',
): PendingReplyUpload {
  const uploadId = `reply-upload-${Date.now()}-${pendingReplyUploadSequence++}`;

  return {
    id: uploadId,
    conversationId,
    kind,
    attachments: files.map((file, index) => ({
      id: `${uploadId}-${index}`,
      name: file.name || `${kind}-${index + 1}`,
      byteSize: file.size,
      previewUrl:
        kind === 'image' && typeof URL !== 'undefined'
          ? URL.createObjectURL(file)
          : null,
      progress: 0,
      status: 'uploading',
      statusLabel: null,
    })),
  };
}

function markPendingReplyUploadFailed(
  pendingUploadId: string,
  label: string,
  includeUploaded = false,
): void {
  const pendingUpload = pendingReplyUploads.value.find(
    (upload) => upload.id === pendingUploadId,
  );
  if (!pendingUpload) return;

  for (const attachment of pendingUpload.attachments) {
    if (includeUploaded || attachment.status !== 'uploaded') {
      attachment.status = 'failed';
      attachment.statusLabel = label;
    }
  }
}

function removePendingReplyUpload(pendingUploadId: string): void {
  const pendingUpload = pendingReplyUploads.value.find(
    (upload) => upload.id === pendingUploadId,
  );
  if (pendingUpload) {
    revokePendingReplyUploadPreviews(pendingUpload);
  }

  pendingReplyUploads.value = pendingReplyUploads.value.filter(
    (upload) => upload.id !== pendingUploadId,
  );

  if (pendingReplyUploads.value.length === 0) {
    replyForm.clearErrors('attachment_ids');
  }
}

function clearPendingReplyUploads(): void {
  for (const pendingUpload of pendingReplyUploads.value) {
    revokePendingReplyUploadPreviews(pendingUpload);
  }

  pendingReplyUploads.value = [];
}

function revokePendingReplyUploadPreviews(
  pendingUpload: PendingReplyUpload,
): void {
  if (typeof URL === 'undefined') return;

  for (const attachment of pendingUpload.attachments) {
    if (attachment.previewUrl?.startsWith('blob:')) {
      URL.revokeObjectURL(attachment.previewUrl);
    }
  }
}

function pendingReplyAttachmentStatusLabel(
  attachment: PendingReplyAttachment,
): string {
  if (attachment.status === 'failed') {
    return attachment.statusLabel ?? t('上传失败');
  }

  return `${attachment.progress}%`;
}

function handleComposerPaste(event: ClipboardEvent): void {
  if (!props.selection?.can_reply || isReplyActionDisabled.value) return;

  const imageFiles = pastedImageFiles(event);
  if (imageFiles.length === 0) return;

  event.preventDefault();
  void uploadAndSendReplyAttachments(imageFiles, 'conversation_image', 'image');
}

function pastedImageFiles(event: ClipboardEvent): File[] {
  const items = Array.from(event.clipboardData?.items ?? []);

  return items
    .filter((item) => item.kind === 'file' && item.type.startsWith('image/'))
    .map((item, index) => {
      const file = item.getAsFile();

      return file ? normalizePastedImageFile(file, index) : null;
    })
    .filter((file): file is File => file !== null);
}

function normalizePastedImageFile(file: File, index: number): File {
  if (file.name) {
    return file;
  }

  return new File([file], `pasted-image-${Date.now()}-${index + 1}.png`, {
    type: file.type || 'image/png',
  });
}

async function insertReplyEmoji(emoji: string): Promise<void> {
  if (!props.selection?.can_reply) return;

  const composer = replyComposerRef.value;
  const start = composer?.selectionStart ?? replyForm.content.length;
  const end = composer?.selectionEnd ?? replyForm.content.length;

  replyForm.content = [
    replyForm.content.slice(0, start),
    emoji,
    replyForm.content.slice(end),
  ].join('');
  emojiPopoverOpen.value = false;

  await nextTick();

  const nextCursor = start + emoji.length;
  replyComposerRef.value?.focus({ preventScroll: true });
  replyComposerRef.value?.setSelectionRange(nextCursor, nextCursor);
}

function detectCannedReplyTrigger(): void {
  if (!props.selection?.can_reply) {
    cannedReplyPickerOpen.value = false;
    return;
  }

  const composer = replyComposerRef.value;
  if (!composer) {
    return;
  }

  const cursor = composer.selectionStart ?? replyForm.content.length;
  const before = replyForm.content.slice(0, cursor);
  // 仅当 `/` 出现在行首或换行后才识别为快捷回复触发，避免和 URL `/path` 等冲突。
  const match = /(^|\n)\/(\S{0,32})$/u.exec(before);

  if (!match) {
    if (cannedReplyPickerOpen.value) {
      cannedReplyPickerOpen.value = false;
      cannedReplyTriggerRange.value = null;
    }
    return;
  }

  const slashIndex = before.length - match[2].length - 1;
  cannedReplyTriggerRange.value = { start: slashIndex, end: cursor };
  cannedReplyPickerQuery.value = match[2];
  cannedReplyPickerOpen.value = true;
}

function openCannedReplyPicker(): void {
  if (!props.selection?.can_reply) {
    return;
  }
  cannedReplyTriggerRange.value = null;
  cannedReplyPickerQuery.value = '';
  cannedReplyPickerOpen.value = true;
}

async function applyCannedReplyContent(payload: {
  rendered_content: string;
  warnings?: string[];
}): Promise<void> {
  const composer = replyComposerRef.value;
  const range = cannedReplyTriggerRange.value;

  if (!composer) {
    replyForm.content += payload.rendered_content;
  } else if (range) {
    const before = replyForm.content.slice(0, range.start);
    const after = replyForm.content.slice(range.end);
    replyForm.content = `${before}${payload.rendered_content}${after}`;
  } else {
    const start = composer.selectionStart ?? replyForm.content.length;
    const end = composer.selectionEnd ?? start;
    const before = replyForm.content.slice(0, start);
    const after = replyForm.content.slice(end);
    replyForm.content = `${before}${payload.rendered_content}${after}`;
  }

  cannedReplyTriggerRange.value = null;
  cannedReplyPickerQuery.value = '';
  cannedReplyPickerOpen.value = false;

  if (payload.warnings?.length) {
    toast.warning(payload.warnings.join('\n'));
  }

  await nextTick();
  composer?.focus({ preventScroll: true });
}

function handleCannedReplyError(message: string): void {
  console.warn('[canned-reply]', message);
}

function handleComposerKeydown(event: KeyboardEvent): void {
  if (cannedReplyPickerOpen.value) {
    if (
      event.key === 'ArrowDown' ||
      event.key === 'ArrowUp' ||
      event.key === 'Escape' ||
      (event.key === 'Enter' && !event.isComposing)
    ) {
      // 把键盘事件转交给 picker 处理；picker 内部会拦截并阻止默认行为。
      event.preventDefault();
      cannedReplyPickerRef.value?.handleKeydown(event);
      return;
    }
  }

  if (
    event.key !== 'Enter' ||
    event.shiftKey ||
    event.metaKey ||
    event.ctrlKey ||
    event.altKey ||
    event.isComposing
  ) {
    return;
  }

  event.preventDefault();
  submitReply();
}

async function focusReplyComposer(conversationId: string): Promise<void> {
  if (typeof window === 'undefined') return;

  await nextTick();

  window.requestAnimationFrame(() => {
    if (props.selection?.conversation.id !== conversationId) return;
    if (!props.selection?.can_reply) return;

    replyComposerRef.value?.focus({ preventScroll: true });
  });
}

const scrollTimelineToBottom = async (): Promise<void> => {
  if (typeof window === 'undefined') return;

  await nextTick();
  const el = timelineScrollRef.value;
  if (!el) return;

  el.scrollTop = el.scrollHeight;
  window.requestAnimationFrame(() => {
    const current = timelineScrollRef.value;
    if (current) {
      current.scrollTop = current.scrollHeight;
    }
  });
};

function isTimelineNearBottom(threshold = TIMELINE_SCROLL_EDGE_PX): boolean {
  const el = timelineScrollRef.value;

  if (!el) {
    return false;
  }

  return el.scrollHeight - el.scrollTop - el.clientHeight <= threshold;
}

function handleTimelineMediaLoad(event: Event): void {
  if (
    event.target instanceof HTMLImageElement &&
    event.target.dataset.messageAttachmentImage === 'true' &&
    activeStitchedTimeline.value?.next_cursor === null &&
    isTimelineNearBottom(480)
  ) {
    void scrollTimelineToBottom();
  }
}

watch(
  () => props.selection?.stitched_timeline,
  (timeline) => {
    stitchedTimeline.value = timeline ?? null;
  },
  { immediate: true },
);

watch(
  () => [
    props.selection?.conversation.id,
    props.selection?.stitched_timeline.entries.length,
    visiblePendingReplyUploads.value.length,
  ],
  () => {
    if (props.selection) {
      void scrollTimelineToBottom();
    }
  },
  { immediate: true, flush: 'post' },
);

/** 保存收件箱事件消息显示偏好。 */
watch(showTimelineEvents, (value) => {
  if (typeof window === 'undefined') {
    return;
  }

  window.localStorage.setItem(
    SHOW_TIMELINE_EVENTS_STORAGE_KEY,
    value ? 'true' : 'false',
  );
});

/** 保存收件箱自动翻译偏好。 */
watch(autoTranslateVisibleMessages, (value) => {
  if (typeof window === 'undefined') {
    return;
  }

  window.localStorage.setItem(
    AUTO_TRANSLATE_VISIBLE_STORAGE_KEY,
    value ? 'true' : 'false',
  );
  if (value) {
    scheduleAutoTranslateObserverRefresh();
    scheduleSummaryAutoTranslateObserverRefresh();
    return;
  }

  stopAutoTranslateObserverAndTimers();
  stopSummaryAutoTranslateObserverAndTimers();
});

/** 保存发送框自动翻译偏好。 */
watch(autoTranslateReply, (value) => {
  if (typeof window === 'undefined') {
    return;
  }

  window.localStorage.setItem(
    AUTO_TRANSLATE_REPLY_STORAGE_KEY,
    value ? 'true' : 'false',
  );

  if (value) {
    replyTranslation.schedule();
    return;
  }

  replyTranslation.clear(replyForm);
});

watch(
  () => props.selection?.conversation.id,
  () => {
    clearReplyQuote();
    replyPolishOpen.value = false;
    resetReplyPolishPreview({ clearCache: true });
  },
);

watch(
  () => [replyForm.content, replyQuote.value?.id],
  () => {
    if (!replyPolishOpen.value) {
      return;
    }

    scheduleReplyPolish();
  },
);

function claimConversation(): void {
  const conversation = props.selection?.conversation;
  if (!conversation) return;
  router.post(
    inboxActions.conversations.claim.url({
      conversation: conversation.id,
    }),
    {},
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => flushInboxReload(),
    },
  );
}

function switchCurrentUserOnline(): void {
  if (updatingOnlineStatus.value) {
    return;
  }

  updatingOnlineStatus.value = true;
  router.put(
    workspace.onlineStatus.update.url(),
    { online_status: 1 },
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        updatingOnlineStatus.value = false;
      },
    },
  );
}

function releaseConversationToAi(): void {
  const conversation = props.selection?.conversation;
  if (!conversation) return;
  router.post(
    inboxActions.conversations.releaseToAi.url({
      conversation: conversation.id,
    }),
    {},
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => flushInboxReload(),
    },
  );
}

async function toggleSelectionImportance(): Promise<void> {
  const profile = props.selection?.contact;
  if (!profile || importanceProcessing.value) return;

  importanceProcessing.value = true;
  try {
    await axios.put(
      workspace.contacts.importance.update.url({
        id: profile.id,
      }),
      { is_important: !profile.is_important },
    );
    router.reload({
      only: ['conversation_list', 'selection', 'tab_counts'],
    });
  } finally {
    importanceProcessing.value = false;
  }
}

function transferConversationToTeammate(targetUserId: string): void {
  const conversation = props.selection?.conversation;
  if (!conversation) return;

  transferForm.target_user_id = targetUserId;
  transferForm.post(
    inboxActions.conversations.transfer.url({
      conversation: conversation.id,
    }),
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => flushInboxReload(),
    },
  );
}

function reopenConversation(): void {
  const conversation = props.selection?.conversation;
  if (!conversation) return;
  router.post(
    inboxActions.conversations.reopen.url({
      conversation: conversation.id,
    }),
    {},
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => flushInboxReload(),
    },
  );
}

function recallMessage(conversationId: string, messageId: string): void {
  if (!conversationId || !messageId) {
    return;
  }

  router.post(
    inboxActions.conversations.messages.recall.url({
      conversation: conversationId,
      message: messageId,
    }),
    {},
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => flushInboxReload(),
    },
  );
}

function quoteMessage(entry: ContactTimelineEntryData): void {
  if (entry.type !== 'message' || entry.recalled_at) {
    return;
  }

  replyQuote.value = {
    id: entry.id,
    senderName: quoteSenderName(entry),
    preview: quotePreview(entry),
    content: entry.content,
    attachments: quoteAttachments(entry),
  };
  replyForm.quoted_message_id = entry.id;
  void focusReplyComposer(entry.conversation_id);
}

function clearReplyQuote(): void {
  replyQuote.value = null;
  replyForm.quoted_message_id = null;
}

function quoteSenderName(entry: ContactTimelineEntryData): string {
  if (entry.role === 'visitor') {
    return formatVisitorName(
      props.selection?.contact?.name,
      props.selection?.contact?.id,
    );
  }
  if (entry.role === 'ai') {
    return entry.sender_name || t('AI 助手');
  }

  return entry.sender_name || t('客服');
}

function quotePreview(entry: ContactTimelineEntryData): string {
  if (typeof entry.content === 'string' && entry.content.trim().length > 0) {
    return entry.content.replace(/\s+/g, ' ').slice(0, 120);
  }
  if (entry.kind === 'image') {
    return t('图片');
  }
  if (entry.kind === 'file') {
    return t('文件');
  }

  return t('无内容');
}

function quoteAttachments(
  entry: ContactTimelineEntryData,
): ReplyQuoteAttachment[] {
  const raw = entry.payload?.attachments;
  if (Array.isArray(raw)) {
    return raw as ReplyQuoteAttachment[];
  }
  if (raw && typeof raw === 'object') {
    return Object.values(raw) as ReplyQuoteAttachment[];
  }

  return [];
}

function replyQuoteImage(quote: ReplyQuoteTarget): ReplyQuoteAttachment | null {
  return (
    quote.attachments.find((attachment) =>
      attachment.mime_type.startsWith('image/'),
    ) ?? null
  );
}

function replyQuoteFile(quote: ReplyQuoteTarget): ReplyQuoteAttachment | null {
  return (
    quote.attachments.find(
      (attachment) => !attachment.mime_type.startsWith('image/'),
    ) ?? null
  );
}

function replyQuoteFullContent(quote: ReplyQuoteTarget): string {
  const content = quote.content?.trim();
  if (content) {
    return content;
  }

  return quote.preview || t('无内容');
}

function openReplyQuoteTarget(quote: ReplyQuoteTarget): void {
  const image = replyQuoteImage(quote);
  if (image) {
    replyQuotePreviewImages.value = [image];
    replyQuotePreviewInitialId.value = image.id;
    replyQuotePreviewOpen.value = true;
    return;
  }

  const file = replyQuoteFile(quote);
  if (file) {
    window.open(file.url, '_blank', 'noopener,noreferrer');
    return;
  }

  replyQuoteDialogTitle.value = quote.senderName;
  replyQuoteDialogContent.value = replyQuoteFullContent(quote);
  replyQuoteTextDialogOpen.value = true;
}

function reeditRecalledMessage(content: string): void {
  if (typeof content !== 'string' || content.length === 0) {
    return;
  }

  const existing = replyForm.content;
  // 「追加」语义：如果输入框非空，加换行隔开，避免黏连旧文本。
  if (existing.length > 0) {
    replyForm.content = existing.endsWith('\n')
      ? existing + content
      : `${existing}\n${content}`;
  } else {
    replyForm.content = content;
  }
  replyForm.clearErrors('content');
}

const claimButtonLabel = computed(() => {
  if (isAiOwnedSelection.value) {
    return t('转接人工');
  }

  const assignedUserId = props.selection?.conversation.assigned_user_id;
  if (assignedUserId && assignedUserId !== currentUserId.value) {
    return t('接管');
  }

  return t('接单');
});

const closeForm = useForm({});

function closeConversation(): void {
  const conversation = props.selection?.conversation;
  if (!conversation) return;
  closeForm.post(
    inboxActions.conversations.close.url({
      conversation: conversation.id,
    }),
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => flushInboxReload(),
    },
  );
}

function contactInitial(conversation: ListConversationItemData): string {
  return getAvatarInitial(conversation.contact_name);
}

/**
 * 列表显示紧凑时间，title 保留完整时间。
 */
function formatLastActivity(conversation: ListConversationItemData): {
  short: string;
  full: string;
} {
  const ts = conversation.last_message_at ?? conversation.created_at;
  return formatRelativeShortWithTooltip(ts);
}

/**
 * 只展示对当前用户有行动意义的收件箱状态。
 */
interface InboxStatusBadge {
  label: string;
}

function inboxStatusBadgeForCurrent(
  status: string,
  inboxStatus: string,
  inboxStatusLabel: string,
  waitingForVisitorReplyLabel: string | null,
  assignedUserId: string | null,
  assignedUserName: string | null,
): InboxStatusBadge | null {
  // 已关闭会话不展示操作状态徽标。
  if (status === 'closed') {
    return null;
  }

  if (waitingForVisitorReplyLabel) {
    return { label: waitingForVisitorReplyLabel };
  }

  if (inboxStatus === 'ai_handling') {
    return { label: inboxStatusLabel };
  }

  if (inboxStatus === 'teammate_pending') {
    return { label: inboxStatusLabel };
  }

  if (inboxStatus === 'teammate_handling') {
    if (assignedUserId && assignedUserId === currentUserId.value) {
      return { label: t('我负责的') };
    }
    if (assignedUserName) {
      return { label: t('由 {name} 接待', { name: assignedUserName }) };
    }
    return null;
  }

  return null;
}

function selectionInboxStatusLabel(
  conversation: ConversationSummaryData,
): string | null {
  return (
    inboxStatusBadgeForCurrent(
      conversation.status,
      conversation.inbox_status,
      conversation.inbox_status_label,
      conversation.waiting_for_visitor_reply_label,
      conversation.assigned_user_id,
      conversation.assigned_user_name,
    )?.label ?? null
  );
}

function formatUnreadCount(value: number): string {
  if (value > 99) {
    return '99+';
  }

  return String(value);
}

const currentInboxView = computed<InboxView>(
  () => (props.current_view as InboxView) ?? 'pending',
);
</script>

<template>
  <AppLayout hide-header>
    <Head :title="t('收件箱')" />
    <div class="relative flex h-[calc(100svh-1rem)] min-h-0 overflow-hidden">
      <!-- 中间：会话列表 -->
      <section class="flex min-h-0 w-78 shrink-0 flex-col border-r">
        <InboxToolbar
          :current-view="currentInboxView"
          :current-channel-id="props.current_channel_id"
          :current-assignee="props.current_assignee"
          :current-search="props.current_search"
          :current-important-only="props.current_important_only"
          :current-conversation-id="props.current_conversation_id"
          :enabled-web-channels="props.enabled_web_channels"
          :teammates="props.teammates"
          :tab-counts="props.tab_counts"
        />
        <div
          v-if="props.conversation_list.length === 0"
          class="p-6 text-center text-sm text-muted-foreground"
        >
          {{ t('暂无会话') }}
        </div>
        <div v-else class="min-h-0 flex-1 divide-y overflow-y-auto">
          <button
            v-for="conversation in props.conversation_list"
            :key="conversation.id"
            type="button"
            class="flex w-full cursor-pointer gap-3 px-3 py-3 text-left transition-colors hover:bg-muted/50"
            :class="{
              'bg-muted': props.current_conversation_id === conversation.id,
            }"
            @click="selectConversation(conversation)"
          >
            <Avatar class="size-10 shrink-0 rounded-md">
              <AvatarFallback class="rounded-md bg-muted-foreground/10 text-xs">
                {{ contactInitial(conversation) }}
              </AvatarFallback>
            </Avatar>
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
              <div class="flex min-w-0 items-baseline gap-2">
                <div class="flex min-w-0 flex-1 items-center gap-1.5">
                  <Star
                    v-if="conversation.contact_is_important"
                    class="size-3.5 shrink-0 fill-current text-foreground"
                    :title="t('重点客户')"
                  />
                  <span class="min-w-0 truncate text-sm leading-5 font-medium">
                    {{
                      formatVisitorName(
                        conversation.contact_name,
                        conversation.contact_id,
                      )
                    }}
                  </span>
                </div>
                <div
                  class="relative -top-1 shrink-0 text-[10px] leading-4 text-muted-foreground tabular-nums"
                  :title="formatLastActivity(conversation).full"
                >
                  {{ formatLastActivity(conversation).short }}
                </div>
              </div>
              <div class="flex min-w-0 items-center gap-1.5">
                <div
                  class="min-w-0 flex-1 truncate text-xs leading-5 text-muted-foreground"
                >
                  {{
                    conversation.display_last_message_preview ||
                    conversation.last_message_preview ||
                    t('暂无消息')
                  }}
                </div>
                <Badge
                  v-if="displayedUnreadCount(conversation) > 0"
                  variant="default"
                  class="h-4 shrink-0 px-1.5 text-[10px] tabular-nums"
                  :title="t('未读访客消息')"
                >
                  {{ formatUnreadCount(displayedUnreadCount(conversation)) }}
                </Badge>
              </div>
            </div>
          </button>
        </div>
      </section>

      <!-- 右侧：选中会话详情 -->
      <section class="flex min-h-0 min-w-0 flex-1 flex-col">
        <template v-if="props.selection">
          <header class="flex shrink-0 items-center gap-3 border-b px-4 py-3">
            <Avatar class="size-9 shrink-0">
              <AvatarImage
                v-if="props.selection.contact?.avatar_url"
                :src="props.selection.contact.avatar_url"
                :alt="props.selection.contact.name ?? ''"
              />
              <AvatarFallback class="bg-muted-foreground/10 text-xs">
                {{ getAvatarInitial(props.selection.contact?.name) }}
              </AvatarFallback>
            </Avatar>
            <div class="min-w-0 flex-1">
              <div class="flex min-w-0 items-center gap-2">
                <button
                  v-if="props.selection.contact"
                  type="button"
                  class="inline-flex size-5 shrink-0 items-center justify-center rounded-sm text-muted-foreground transition-colors hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
                  :aria-label="importanceToggleTitle"
                  :aria-pressed="selectedContactImportant"
                  :title="importanceToggleTitle"
                  :disabled="importanceProcessing"
                  :class="
                    selectedContactImportant
                      ? 'text-foreground'
                      : 'text-muted-foreground'
                  "
                  @click="toggleSelectionImportance"
                >
                  <Star
                    class="size-3.5"
                    :class="{ 'fill-current': selectedContactImportant }"
                  />
                </button>
                <div class="min-w-0 truncate text-sm font-semibold">
                  {{
                    formatVisitorName(
                      props.selection.contact?.name,
                      props.selection.contact?.id,
                    )
                  }}
                </div>
              </div>
              <div
                class="flex items-center gap-2 text-xs text-muted-foreground"
              >
                <Badge
                  v-if="selectionInboxStatusLabel(props.selection.conversation)"
                  variant="outline"
                  class="h-5 px-1.5 text-[10px]"
                >
                  {{ selectionInboxStatusLabel(props.selection.conversation) }}
                </Badge>
                <Badge variant="outline" class="h-5 px-1.5 text-[10px]">
                  {{ props.selection.conversation.status_label }}
                </Badge>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                :aria-label="t('搜索聊天记录')"
                :title="t('搜索聊天记录')"
                :class="[
                  'gap-1.5',
                  messageSearchActive
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground',
                ]"
                @click="
                  messageSearchActive
                    ? closeMessageSearch()
                    : openMessageSearch()
                "
              >
                <Search class="size-3.5" />
              </Button>
              <Button
                v-if="props.selection.can_claim"
                variant="outline"
                size="sm"
                @click="claimConversation"
              >
                {{ claimButtonLabel }}
              </Button>
              <DropdownMenu v-if="canTransferToTeammate">
                <DropdownMenuTrigger as-child>
                  <Button
                    variant="outline"
                    size="sm"
                    :disabled="transferForm.processing"
                  >
                    <UserRound class="mr-1 size-3.5" />
                    {{ t('转接') }}
                    <ChevronDown class="ml-1 size-3.5 opacity-60" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-52">
                  <DropdownMenuItem
                    v-for="teammate in transferTeammates"
                    :key="teammate.id"
                    class="flex flex-col items-start gap-0.5"
                    @select="transferConversationToTeammate(teammate.id)"
                  >
                    <span class="max-w-full truncate">{{ teammate.name }}</span>
                    <span
                      v-if="teammate.email"
                      class="max-w-full truncate text-xs text-muted-foreground"
                    >
                      {{ teammate.email }}
                    </span>
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
              <Button
                v-if="props.selection.can_release_to_ai"
                variant="outline"
                size="sm"
                @click="releaseConversationToAi"
              >
                {{ t('交给 AI') }}
              </Button>
              <DropdownMenu>
                <DropdownMenuTrigger as-child>
                  <Button variant="outline" size="sm" :aria-label="t('更多')">
                    {{ t('更多') }}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-48">
                  <DropdownMenuItem
                    v-if="props.selection.can_translate_messages"
                    class="gap-2"
                    @select="toggleAutoTranslateVisibleMessages()"
                  >
                    <Languages class="size-3.5" />
                    {{ autoTranslateVisibleToggleTitle }}
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    class="gap-2"
                    @select="toggleTimelineEvents()"
                  >
                    <Eye v-if="showTimelineEvents" class="size-3.5" />
                    <EyeOff v-else class="size-3.5" />
                    {{ timelineEventsToggleTitle }}
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    v-if="props.selection.can_reopen"
                    class="gap-2"
                    @select="reopenConversation()"
                  >
                    <RotateCcw class="size-3.5" />
                    {{ t('重新打开') }}
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    v-if="props.selection.can_close"
                    class="gap-2"
                    :disabled="closeForm.processing"
                    @select="closeConversation()"
                  >
                    <X class="size-3.5" />
                    {{ t('结束会话') }}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </header>

          <div
            v-if="messageSearchActive"
            class="flex shrink-0 items-center gap-2 border-b px-4 py-2"
          >
            <Search class="size-4 shrink-0 text-muted-foreground" />
            <input
              ref="messageSearchInputRef"
              v-model="messageSearchQuery"
              type="text"
              class="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
              @input="handleMessageSearchInput"
              @keydown.escape="closeMessageSearch"
            />
            <span
              v-if="
                messageSearchQuery.trim() &&
                !messageSearchLoading &&
                messageSearchResults.length > 0
              "
              class="shrink-0 text-xs text-muted-foreground"
            >
              {{ messageSearchResults.length }}
              {{ t('条结果') }}
            </span>
            <button
              type="button"
              class="inline-flex size-5 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
              :aria-label="t('关闭搜索')"
              @click="closeMessageSearch"
            >
              <X class="size-3.5" />
            </button>
          </div>

          <div
            v-if="messageSearchActive && messageSearchQuery.trim()"
            class="min-h-0 flex-1 overflow-y-auto px-4 py-3"
          >
            <InboxMessageSearchResults
              :results="messageSearchResults"
              :conversations="activeStitchedTimeline?.conversations ?? []"
              :search="messageSearchQuery"
              :loading="messageSearchLoading || timelineLoadingAnchor"
              @select="focusTimelineSearchResult"
            />
          </div>
          <div
            v-else-if="messageSearchActive"
            class="flex min-h-0 flex-1 items-center justify-center text-sm text-muted-foreground"
          >
            {{ t('输入关键词搜索聊天记录') }}
          </div>
          <div
            v-else
            ref="timelineScrollRef"
            class="min-h-0 flex-1 overflow-y-auto px-6 pb-3"
            @load.capture="handleTimelineMediaLoad"
            @scroll="handleTimelineScroll"
          >
            <div v-if="activeStitchedTimeline" class="w-full">
              <!-- 本次会话总结固定在消息区顶部，紧贴顶部无空隙，消息从其下方滚过 -->
              <div
                v-if="props.selection.conversation.summary"
                class="sticky top-0 z-10 -mx-6 bg-background px-6 pb-3"
              >
                <ConversationSummaryBlock
                  :data-inbox-conversation-summary-id="
                    props.selection.conversation.id
                  "
                  :conversation="props.selection.conversation"
                  :current-user-locale="currentUserLocale"
                  :available-tags="props.available_conversation_tags"
                  :is-translating="
                    autoTranslatingSummaryIds.has(
                      props.selection.conversation.id,
                    )
                  "
                  variant="current"
                />
              </div>
              <div
                v-if="timelineLoadingPrevious"
                class="py-2 text-center text-xs text-muted-foreground"
              >
                {{ t('加载中...') }}
              </div>
              <InboxStitchedTimeline
                :timeline="activeStitchedTimeline"
                :contact-summary="props.selection.contact"
                :current-conversation-id="props.selection.conversation.id"
                :current-user-id="currentUserId"
                :can-recall-in-current="Boolean(props.selection.can_reply)"
                :translating-message-ids="autoTranslatingMessageIds"
                :translating-summary-ids="autoTranslatingSummaryIds"
                :translation-locale="currentUserLocale"
                :current-user-locale="currentUserLocale"
                :available-conversation-tags="props.available_conversation_tags"
                :show-events="showTimelineEvents"
                :highlighted-message-id="highlightedTimelineMessageId"
                @recall="recallMessage"
                @reedit="reeditRecalledMessage"
                @quote="quoteMessage"
              />
              <div
                v-if="timelineLoadingNext"
                class="py-2 text-center text-xs text-muted-foreground"
              >
                {{ t('加载中...') }}
              </div>
            </div>
          </div>

          <footer class="shrink-0 border-t border-border/60 bg-background p-2">
            <div
              v-if="props.selection.can_reply && isCurrentUserOffline"
              class="mb-2 flex flex-col items-stretch gap-2 rounded-md border bg-muted/30 px-3 py-2 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
            >
              <span class="min-w-0 flex-1 leading-5">
                {{
                  t(
                    '你当前处于离线状态，回复只会处理此会话，不会接收新的转人工会话。',
                  )
                }}
              </span>
              <Button
                type="button"
                variant="outline"
                size="sm"
                class="h-7 w-full rounded-md px-2 text-xs sm:w-auto"
                :disabled="updatingOnlineStatus"
                @click="switchCurrentUserOnline"
              >
                {{ t('切换在线') }}
              </Button>
            </div>
            <div
              v-if="replyForm.errors.content"
              class="mb-2 text-xs text-destructive"
            >
              {{ replyForm.errors.content }}
            </div>
            <div
              v-if="replyAttachmentError"
              class="mb-2 text-xs text-destructive"
            >
              {{ replyAttachmentError }}
            </div>
            <div
              v-if="visiblePendingReplyUploads.length > 0"
              class="mb-2 flex flex-wrap gap-2"
            >
              <template
                v-for="pendingUpload in visiblePendingReplyUploads"
                :key="pendingUpload.id"
              >
                <div
                  v-for="attachment in pendingUpload.attachments"
                  :key="attachment.id"
                  class="relative"
                >
                  <img
                    v-if="
                      pendingUpload.kind === 'image' && attachment.previewUrl
                    "
                    :src="attachment.previewUrl"
                    :alt="attachment.name"
                    class="h-16 w-16 rounded-lg object-cover"
                  />
                  <div
                    v-else-if="pendingUpload.kind === 'image'"
                    class="flex h-16 w-16 items-center justify-center rounded-lg border bg-muted/40 text-muted-foreground"
                  >
                    <ImageIcon class="size-4" />
                  </div>
                  <div
                    v-else
                    class="flex h-16 max-w-40 items-center gap-2 rounded-lg border bg-background/60 px-2"
                  >
                    <Paperclip class="size-4 shrink-0 text-muted-foreground" />
                    <div class="min-w-0 text-xs">
                      <div class="truncate font-medium">
                        {{ attachment.name }}
                      </div>
                      <div class="text-muted-foreground">
                        {{ formatFileSize(attachment.byteSize) }}
                      </div>
                    </div>
                  </div>
                  <div
                    v-if="attachment.status !== 'uploaded'"
                    class="absolute inset-0 flex items-center justify-center rounded-lg bg-black/40 text-[11px] font-medium text-white"
                  >
                    {{ pendingReplyAttachmentStatusLabel(attachment) }}
                  </div>
                  <button
                    v-if="attachment.status === 'failed'"
                    type="button"
                    class="absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full bg-destructive text-white shadow-sm"
                    :title="t('移除')"
                    @click="removePendingReplyUpload(pendingUpload.id)"
                  >
                    <X class="size-2.5" />
                  </button>
                </div>
              </template>
            </div>
            <div
              v-if="showReplyTranslationPreview"
              class="mb-2 rounded-md border bg-muted/30 px-3 py-2"
            >
              <div
                class="mb-1 flex items-center justify-between gap-2 text-xs text-muted-foreground"
              >
                <span>{{ replyTranslationTitle }}</span>
                <span v-if="replyTranslationLoading">{{ t('翻译中') }}</span>
              </div>
              <Textarea
                v-model="replyTranslationDraft"
                rows="2"
                class="min-h-16 resize-y bg-background text-sm"
                :disabled="
                  replyExpectedVisitorLocale === null ||
                  (replyTranslationLoading && !replyTranslationDraft)
                "
                @input="replyTranslationTouched = true"
              />
              <div
                v-if="replyTranslationRequirementMessage"
                class="mt-1 text-xs"
                :class="
                  replyTranslationError || replyExpectedVisitorLocale === null
                    ? 'text-destructive'
                    : 'text-muted-foreground'
                "
              >
                {{ replyTranslationRequirementMessage }}
              </div>
            </div>
            <div
              class="overflow-hidden rounded-xl border border-input bg-background shadow-xs transition-[box-shadow,border-color] duration-200 focus-within:border-foreground/20 focus-within:shadow-sm dark:bg-neutral-950"
              :class="{ 'opacity-60': !props.selection.can_reply }"
            >
              <input
                ref="replyFileInputRef"
                type="file"
                class="sr-only"
                multiple
                :disabled="isReplyActionDisabled"
                @change="handleReplyFileChange"
              />
              <input
                ref="replyImageInputRef"
                type="file"
                class="sr-only"
                multiple
                accept="image/*"
                :disabled="isReplyActionDisabled"
                @change="handleReplyImageChange"
              />
              <div class="relative">
                <textarea
                  ref="replyComposerRef"
                  v-model="replyForm.content"
                  :disabled="!props.selection.can_reply || replyForm.processing"
                  class="block h-36 w-full resize-none overflow-y-auto bg-transparent px-3 pt-3 pb-6 text-sm leading-7 outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
                  @keydown="handleComposerKeydown"
                  @paste="handleComposerPaste"
                  @input="detectCannedReplyTrigger"
                  @keyup="detectCannedReplyTrigger"
                  @click="detectCannedReplyTrigger"
                ></textarea>
                <div
                  v-if="replyQuote"
                  class="absolute inset-x-3 bottom-1 flex items-center gap-2 text-xs text-muted-foreground"
                >
                  <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center text-left transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                    @click="openReplyQuoteTarget(replyQuote)"
                  >
                    <span class="max-w-[45%] shrink-0 truncate font-medium">
                      {{ replyQuote.senderName }}：
                    </span>
                    <span class="min-w-0 truncate">
                      {{ replyQuote.preview }}
                    </span>
                  </button>
                  <button
                    type="button"
                    class="inline-flex size-5 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                    :aria-label="t('取消引用')"
                    :title="t('取消引用')"
                    @click="clearReplyQuote"
                  >
                    <X class="size-3.5" />
                  </button>
                </div>
              </div>
              <div
                class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 px-3 pt-1 pb-2"
              >
                <div class="flex items-center gap-1.5">
                  <Popover v-model:open="emojiPopoverOpen">
                    <PopoverTrigger as-child>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-6 rounded-md text-muted-foreground hover:bg-muted hover:text-foreground disabled:text-muted-foreground/50"
                        :disabled="isReplyActionDisabled"
                        :aria-label="t('选择表情')"
                        :title="t('选择表情')"
                      >
                        <Smile class="size-4" />
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent class="w-64 p-2" align="start">
                      <div class="max-h-48 overflow-y-auto">
                        <div class="grid grid-cols-7 gap-1">
                          <button
                            v-for="emoji in COMPOSER_EMOJIS"
                            :key="emoji"
                            type="button"
                            class="flex size-7 items-center justify-center rounded-md text-base transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                            :aria-label="t('选择表情')"
                            @click="insertReplyEmoji(emoji)"
                          >
                            {{ emoji }}
                          </button>
                        </div>
                      </div>
                    </PopoverContent>
                  </Popover>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-6 rounded-md text-muted-foreground hover:bg-muted hover:text-foreground disabled:text-muted-foreground/50"
                    :disabled="isReplyActionDisabled"
                    :aria-label="t('添加附件')"
                    :title="t('添加附件')"
                    @click="replyFileInputRef?.click()"
                  >
                    <Paperclip class="size-4" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-6 rounded-md text-muted-foreground hover:bg-muted hover:text-foreground disabled:text-muted-foreground/50"
                    :disabled="isReplyActionDisabled"
                    :aria-label="t('添加图片')"
                    :title="t('添加图片')"
                    @click="replyImageInputRef?.click()"
                  >
                    <ImageIcon class="size-4" />
                  </Button>
                  <CannedReplyPicker
                    ref="cannedReplyPickerRef"
                    v-model:open="cannedReplyPickerOpen"
                    :conversation-id="props.selection?.conversation.id ?? null"
                    :query="cannedReplyPickerQuery"
                    @rendered="applyCannedReplyContent"
                    @error="handleCannedReplyError"
                  >
                    <template #trigger>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-6 rounded-md text-muted-foreground hover:bg-muted hover:text-foreground disabled:text-muted-foreground/50"
                        :disabled="isReplyActionDisabled"
                        :aria-label="t('快捷回复')"
                        :title="t('快捷回复（输入 / 触发）')"
                        @click="openCannedReplyPicker"
                      >
                        <MessageSquareQuote class="size-4" />
                      </Button>
                    </template>
                  </CannedReplyPicker>
                  <Popover v-model:open="replyPolishOpen">
                    <PopoverTrigger as-child>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-6 rounded-md text-muted-foreground hover:bg-muted hover:text-foreground disabled:text-muted-foreground/50"
                        :disabled="!canUseReplyPolish"
                        :aria-label="replyPolishButtonTitle"
                        :title="replyPolishButtonTitle"
                      >
                        <Loader2
                          v-if="replyPolishLoading"
                          class="size-4 animate-spin"
                        />
                        <Sparkles v-else class="size-4" />
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent
                      class="w-[min(30rem,calc(100vw-2rem))] p-3"
                      align="start"
                    >
                      <div class="space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                          <div class="shrink-0 text-sm font-medium">
                            {{ t('AI 回复助手') }}
                          </div>
                          <div
                            class="flex shrink-0 rounded-md border bg-background p-0.5"
                          >
                            <button
                              v-for="option in replyAssistantModeOptions"
                              :key="option.value"
                              type="button"
                              class="h-7 rounded-sm px-2.5 text-xs font-medium transition-colors"
                              :class="
                                replyPolishSelectedMode === option.value
                                  ? 'bg-foreground text-background'
                                  : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                              "
                              @click="replyPolishSelectedMode = option.value"
                            >
                              {{ option.label }}
                            </button>
                          </div>
                          <div class="ml-auto flex shrink-0 items-center gap-1">
                            <Select
                              v-model="replyPolishSelectedTone"
                              :disabled="replyPolishToneOptions.length === 0"
                            >
                              <SelectTrigger
                                class="h-7 w-24 px-2 text-xs shadow-none"
                                :aria-label="t('语气')"
                              >
                                <SelectValue :placeholder="t('请选择语气')" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectGroup>
                                  <SelectItem
                                    v-for="option in replyPolishToneOptions"
                                    :key="option.value"
                                    :value="option.value"
                                  >
                                    {{ option.label }}
                                  </SelectItem>
                                </SelectGroup>
                              </SelectContent>
                            </Select>
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon"
                              class="size-7 rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                              :disabled="
                                replyPolishLoading || !canUseReplyPolish
                              "
                              :aria-label="t('刷新候选')"
                              :title="t('刷新候选')"
                              @click="refreshReplyPolishCandidates"
                            >
                              <RefreshCw class="size-4" />
                            </Button>
                            <Popover v-model:open="replyPolishSettingsOpen">
                              <PopoverTrigger as-child>
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="icon"
                                  class="size-7 rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                  :aria-label="t('设置')"
                                  :title="t('设置')"
                                >
                                  <SlidersHorizontal class="size-4" />
                                </Button>
                              </PopoverTrigger>
                              <PopoverContent
                                class="w-72 p-3"
                                align="end"
                                side="bottom"
                              >
                                <div class="space-y-2">
                                  <div
                                    class="text-xs font-medium text-muted-foreground"
                                  >
                                    {{ t('模型') }}
                                  </div>
                                  <Select
                                    v-model="replyPolishSelectedModelId"
                                    :disabled="!hasAvailableReplyPolishModels"
                                  >
                                    <SelectTrigger class="h-9 w-full">
                                      <SelectValue
                                        :placeholder="t('请选择模型后再润色')"
                                      />
                                    </SelectTrigger>
                                    <SelectContent>
                                      <SelectGroup
                                        v-for="group in groupedAiModelOptions"
                                        :key="group.providerName"
                                      >
                                        <SelectLabel>{{
                                          group.providerName
                                        }}</SelectLabel>
                                        <SelectItem
                                          v-for="option in group.options"
                                          :key="option.value"
                                          :value="option.value"
                                        >
                                          {{ option.label }}
                                        </SelectItem>
                                      </SelectGroup>
                                    </SelectContent>
                                  </Select>
                                </div>
                              </PopoverContent>
                            </Popover>
                          </div>
                        </div>

                        <div
                          class="h-56 max-h-[calc(100vh-17rem)] overflow-y-auto pr-1"
                        >
                          <div
                            v-if="replyPolishLoading"
                            class="flex h-full items-center justify-center"
                          >
                            <div class="w-28 space-y-2">
                              <div
                                class="h-2.5 w-full animate-pulse rounded bg-muted-foreground/25"
                              />
                              <div
                                class="h-2.5 w-5/6 animate-pulse rounded bg-muted-foreground/20"
                              />
                              <div
                                class="h-2.5 w-2/3 animate-pulse rounded bg-muted-foreground/15"
                              />
                            </div>
                          </div>
                          <div
                            v-else-if="replyPolishCandidates.length > 0"
                            class="space-y-2"
                          >
                            <div
                              v-for="candidate in replyPolishCandidates"
                              :key="candidate.id"
                              class="group flex gap-2 rounded-md border bg-background p-2.5 text-sm leading-6"
                            >
                              <div class="min-w-0 flex-1 whitespace-pre-wrap">
                                {{ candidate.content }}
                              </div>
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-7 shrink-0 rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                :aria-label="t('填入输入框')"
                                :title="t('填入输入框')"
                                @click="
                                  applyReplyPolishCandidate(candidate.content)
                                "
                              >
                                <PencilLine class="size-4" />
                              </Button>
                            </div>
                          </div>
                          <div
                            v-else
                            class="flex h-full items-center justify-center rounded-md border border-dashed px-3 text-center text-xs text-muted-foreground"
                            :class="{ 'text-destructive': replyPolishError }"
                          >
                            {{ replyPolishError ?? t('暂无候选') }}
                          </div>
                        </div>
                      </div>
                    </PopoverContent>
                  </Popover>
                  <Button
                    v-if="
                      props.selection.can_reply &&
                      replyExpectedVisitorLocale !== null
                    "
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-6 rounded-md hover:bg-muted hover:text-foreground disabled:text-muted-foreground/50"
                    :class="
                      autoTranslateReply
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                    "
                    :aria-label="replyAutoTranslateToggleTitle"
                    :aria-pressed="autoTranslateReply"
                    :title="replyAutoTranslateToggleTitle"
                    :disabled="isReplyActionDisabled"
                    @click="toggleReplyAutoTranslate"
                  >
                    <Languages class="size-4" />
                  </Button>
                </div>
                <Button
                  size="sm"
                  class="h-7 rounded-md bg-foreground px-3 text-xs text-background shadow-none hover:bg-foreground/90 disabled:bg-muted disabled:text-muted-foreground"
                  :disabled="!canSubmitReply"
                  @click="submitReply"
                >
                  {{ t('发送') }}
                </Button>
              </div>
            </div>
          </footer>
        </template>

        <div
          v-else
          class="flex min-h-0 flex-1 items-center justify-center text-sm text-muted-foreground"
        >
          {{ t('请从左侧选择一条会话查看详情') }}
        </div>
      </section>

      <div
        v-if="props.selection"
        class="relative min-h-0 w-4 shrink-0 bg-background"
      >
        <button
          v-if="!contextPanelCollapsed"
          type="button"
          class="absolute top-0 left-0 z-20 h-full w-2 -translate-x-1 cursor-col-resize touch-none"
          :aria-label="t('调整资料栏宽度')"
          @pointerdown="startResizeContextPanel"
        />
        <button
          type="button"
          class="absolute top-1/2 left-0 z-30 flex h-12 w-4 -translate-y-1/2 items-center justify-center border border-border bg-muted text-muted-foreground shadow-sm transition-colors hover:bg-muted/80 hover:text-foreground"
          :class="contextPanelToggleClass"
          :title="contextPanelCollapsed ? t('展开资料栏') : t('收起资料栏')"
          :aria-label="
            contextPanelCollapsed ? t('展开资料栏') : t('收起资料栏')
          "
          @click="toggleContextPanel"
        >
          <ChevronLeft v-if="contextPanelCollapsed" class="size-3" />
          <ChevronRight v-else class="size-3" />
        </button>
      </div>

      <!-- 最右：上下文面板（资料 / 接待状态） -->
      <div
        v-if="props.selection && !contextPanelCollapsed"
        class="relative flex min-h-0 min-w-0 shrink-0 bg-background"
        :style="contextPanelStyle"
      >
        <div class="min-h-0 min-w-0 flex-1">
          <InboxContextPanel
            :contact-profile="props.selection.contact_profile"
            :conversation="props.selection.conversation"
            :available-contact-tags="props.available_contact_tags"
            :conversation-id="props.selection.conversation.id"
            :visitor-locale="props.selection.conversation.visitor_locale"
            :reception-language-options="props.reception_language_options"
            :current-user-locale="currentUserLocale"
            :can-translate="props.selection.can_translate_messages"
            :auto-translate-enabled="autoTranslateVisibleMessages"
          />
        </div>
      </div>
    </div>

    <ImagePreviewDialog
      v-if="replyQuotePreviewImages.length"
      v-model:open="replyQuotePreviewOpen"
      :images="replyQuotePreviewImages"
      :initial-id="replyQuotePreviewInitialId"
    />
    <Dialog v-model:open="replyQuoteTextDialogOpen">
      <DialogContent class="max-h-[80vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{{ replyQuoteDialogTitle }}</DialogTitle>
        </DialogHeader>
        <div class="text-sm leading-6 whitespace-pre-wrap">
          {{ replyQuoteDialogContent }}
        </div>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
