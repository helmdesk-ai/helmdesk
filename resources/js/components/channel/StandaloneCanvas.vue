<!--
  文件说明：网站渠道独立访客端聊天画布，承接会话状态、消息发送和附件上传交互。
-->
<script setup lang="ts">
import ChannelPausedNotice from '@/components/channel/ChannelPausedNotice.vue';
import QuotedMessagePreview from '@/components/channel/QuotedMessagePreview.vue';
import StandaloneAttachmentCard from '@/components/channel/StandaloneAttachmentCard.vue';
import ImagePreviewDialog from '@/components/common/ImagePreviewDialog.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuTrigger,
} from '@/components/ui/context-menu';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import {
  type AttachmentPurpose,
  resolveAttachmentUploadError,
  useAttachmentUploader,
} from '@/composables/useAttachmentUploader';
import { COMPOSER_EMOJIS } from '@/lib/composerEmojis';
import { formatFileSize } from '@/lib/format';
import {
  openMercureEventSource,
  receptionConversationTopic,
} from '@/lib/mercure';
import {
  STANDALONE_LOCALE_STORAGE_KEY,
  useStandaloneI18n,
} from '@/standalone/i18n';
import { createReceptionClient } from '@/standalone/receptionClient';
import { injectReceptionCredentials } from '@/standalone/receptionCredentials';
import type {
  PublicStandaloneChannelData,
  ReceptionMessageData,
  ReceptionStateData,
} from '@/types/generated';
import { injectWidgetHostBridge } from '@/widget/useWidgetHostBridge';
import {
  ArrowLeft,
  ArrowUp,
  Image as ImageIcon,
  MessageCircle,
  Paperclip,
  Smile,
  X,
} from '@lucide/vue';
import type { CSSProperties, WatchStopHandle } from 'vue';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = withDefaults(
  defineProps<{
    channel: PublicStandaloneChannelData;
    interactive?: boolean;
    entryMode?: 'standalone' | 'widget';
    // 演示模式：用于后台预览。控件可交互，但不连后端——发消息只在本地回显，便于查看气泡样式。
    demo?: boolean;
  }>(),
  { interactive: false, entryMode: 'standalone', demo: false },
);

// 控件是否可交互：真实联网态或本地演示态都可操作输入区。
const canInteract = computed(() => props.interactive || props.demo);
// 演示模式下的本地消息列表（不落后端）。
const demoMessages = ref<ReceptionMessageData[]>([]);
let demoSeq = 0;

const { locale, t, isSupportedLocale } = useStandaloneI18n();

const widgetHostBridge = injectWidgetHostBridge();
const credentials = injectReceptionCredentials();

// 接待客户端：真实访客入口通过它注入凭证与上下文头。
const receptionClient = credentials
  ? createReceptionClient({
      credentials,
      environmentHeaders: resolveEnvironmentHeaders,
      parseErrorMessage: t('接口返回格式异常'),
      requestErrorMessage: t('发送失败，请稍后重试'),
    })
  : null;

const showWidgetCloseButton = computed(
  () => props.entryMode === 'widget' && widgetHostBridge !== null,
);
const seenNonVisitorMessageIds = new Set<string>();
let widgetUnreadInitialized = false;

function requestWidgetClose(): void {
  widgetHostBridge?.sendToHost('helmdesk:widget:close');
}

const avatarFallback = computed(() => {
  const name = props.channel.assistant_name?.trim();

  if (!name) {
    return 'AI';
  }

  return name.slice(0, 2).toUpperCase();
});

const effectiveGreetingMessage = computed(
  () => props.channel.greeting_message?.trim() ?? '',
);

const hasGreetingContent = computed(
  () => effectiveGreetingMessage.value.length > 0,
);

const state = ref<ReceptionStateData | null>(null);
const loading = ref(false);
const sending = ref(false);
const errorMessage = ref<string | null>(null);
// 渠道已被管理员软删除且当前访客没有进行中的会话时 /state 会返回 410；
// 已有会话的访客不会触发，仍可继续消息往返。
const pausedWithoutSession = ref(false);
const composerValue = ref('');
const composerEl = ref<HTMLTextAreaElement | null>(null);
const fileInputEl = ref<HTMLInputElement | null>(null);
const imageInputEl = ref<HTMLInputElement | null>(null);
const messageListEl = ref<HTMLDivElement | null>(null);
const attachmentUploading = ref(false);
const emojiPopoverOpen = ref(false);
let conversationEventSource: EventSource | null = null;
let subscribedConversationId: string | null = null;

const MAX_CONTENT_LENGTH = 4000;
const MAX_ATTACHMENT_COUNT = 10;
const WIDGET_HOST_CONTEXT_WAIT_MS = 1000;

type PendingComposerAttachmentStatus = 'uploading' | 'uploaded' | 'failed';

interface PendingComposerAttachment {
  id: string;
  name: string;
  byteSize: number;
  previewUrl: string | null;
  progress: number;
  status: PendingComposerAttachmentStatus;
  statusLabel: string | null;
}

interface PendingComposerUpload {
  id: string;
  conversationId: string;
  kind: 'file' | 'image';
  attachments: PendingComposerAttachment[];
}

interface ComposerQuoteTarget {
  id: string;
  senderName: string;
  preview: string;
  content: string | null;
  attachments: ReceptionMessageData['attachments'];
}

const pendingUploads = ref<PendingComposerUpload[]>([]);
const composerQuote = ref<ComposerQuoteTarget | null>(null);
const quotedPreviewOpen = ref(false);
const quotedPreviewImages = ref<ReceptionMessageData['attachments']>([]);
const quotedPreviewInitialId = ref<string | null>(null);
const quotedTextDialogOpen = ref(false);
const quotedTextDialogContent = ref('');
let pendingUploadSequence = 0;

const { upload } = useAttachmentUploader();
// 会话 token 由凭证持有，服务端响应回填后供后续请求带上。
const currentSessionToken = computed(() => {
  if (props.demo) {
    return 'preview';
  }

  return credentials?.sessionToken() ?? '';
});

const isComposerActionDisabled = computed(
  () =>
    !canInteract.value ||
    currentSessionToken.value === '' ||
    sending.value ||
    attachmentUploading.value,
);

const canSend = computed(
  () =>
    canInteract.value &&
    currentSessionToken.value !== '' &&
    !sending.value &&
    !attachmentUploading.value &&
    composerValue.value.trim().length > 0 &&
    composerValue.value.trim().length <= MAX_CONTENT_LENGTH,
);

const visiblePendingUploads = computed(() => {
  const conversationId = state.value?.conversation_id;

  return conversationId
    ? pendingUploads.value.filter(
        (upload) => upload.conversationId === conversationId,
      )
    : [];
});

const messages = computed<ReceptionMessageData[]>(() => {
  if (props.demo) {
    return demoMessages.value;
  }

  if (state.value === null) {
    return [];
  }

  return normalizeReceptionMessages(state.value.messages);
});

// 统一主题色驱动整套渐变背景与气泡视觉，背景永远是渐变（不再支持纯色）。
// 渐变必须落在 background 上（Tailwind 的 bg-* 会落到 background-color，无法承载渐变）。
const pageStyle = computed<CSSProperties>(() => {
  const themeColor = props.channel.theme_color;
  const background = `linear-gradient(160deg, color-mix(in srgb, ${themeColor} 20%, #ffffff), color-mix(in srgb, ${themeColor} 7%, #ffffff) 45%, #ffffff 100%)`;

  return {
    '--standalone-primary': themeColor,
    '--standalone-background': background,
    '--standalone-assistant-bubble': '#ffffff',
    '--standalone-assistant-text': '#111827',
    '--standalone-visitor-bubble': themeColor,
    '--standalone-visitor-text': '#ffffff',
    background,
  } as CSSProperties;
});

// 首页态：home_mode_enabled 时访客先看到欢迎屏，点击「进入聊天」再切到 thread。
const activeView = ref<'home' | 'thread'>(
  props.channel.home_mode_enabled ? 'home' : 'thread',
);

const homeWelcomeMessage = computed(
  () => props.channel.home_welcome_message?.trim() ?? '',
);

// 首页续聊卡片的副文案：优先用欢迎语，缺省退回副标题。
const homeCardHint = computed(
  () => effectiveGreetingMessage.value || props.channel.subtitle?.trim() || '',
);

function enterChat(): void {
  activeView.value = 'thread';
  void focusComposer();
}

function backToHome(): void {
  if (props.channel.home_mode_enabled) {
    activeView.value = 'home';
  }
}

// thread 态顶部是否展示悬浮胶囊标题栏：标题栏开启或处于首页模式（需要返回入口）时展示。
const showThreadHeader = computed(
  () => props.channel.header.enabled || props.channel.home_mode_enabled,
);

const enabledSuggestionItems = computed(() => {
  if (!props.channel.suggestions.enabled) {
    return [];
  }

  return props.channel.suggestions.items
    .map((item) => item.trim())
    .filter(Boolean)
    .slice(0, 6);
});

function normalizeReceptionMessages(value: unknown): ReceptionMessageData[] {
  if (Array.isArray(value)) {
    return value as ReceptionMessageData[];
  }

  throw new Error(t('接口返回格式异常'));
}

const showInlineGreeting = computed(() => {
  if (props.demo) {
    return demoMessages.value.length === 0;
  }

  if (!props.interactive) {
    return true;
  }

  if (state.value === null) {
    return true;
  }

  return messages.value.length === 0;
});

// 演示模式：本地追加一条消息，仅用于在预览里展示气泡样式，不落后端。
function appendDemoMessage(
  role: 'visitor' | 'ai',
  content: string,
  options: {
    kind?: string;
    attachments?: ReceptionMessageData['attachments'];
  } = {},
): void {
  demoSeq += 1;
  demoMessages.value.push({
    id: `preview-${demoSeq}`,
    role,
    kind: options.kind ?? 'text',
    content,
    sender_name: null,
    sender_avatar_url: null,
    created_at: new Date().toISOString(),
    seq_no: demoSeq,
    client_msg_id: null,
    delivery_status: 'sent',
    quoted_message_id: null,
    quoted_message: null,
    recalled_at: null,
    recalled_content: null,
    attachments: options.attachments ?? [],
  });
}

// 演示模式：把选中的文件转成可本地预览的附件数据（图片用 object URL 出缩略图，文件给下载入口）。
function buildDemoAttachments(
  files: File[],
  kind: 'file' | 'image',
): ReceptionMessageData['attachments'] {
  return files.map((file, index) => {
    const objectUrl =
      typeof URL !== 'undefined' ? URL.createObjectURL(file) : '';
    const isImage = file.type.startsWith('image/');

    return {
      id: `preview-att-${demoSeq}-${pendingUploadSequence++}-${index}`,
      name: file.name || `${kind}-${index + 1}`,
      mime_type:
        file.type || (isImage ? 'image/png' : 'application/octet-stream'),
      byte_size: file.size,
      url: objectUrl,
      preview_url: isImage ? objectUrl : null,
      width: null,
      height: null,
    };
  });
}

// 演示模式回收本地附件创建的 object URL，避免预览反复重置后内存泄漏。
function clearDemoMessages(): void {
  if (typeof URL !== 'undefined') {
    for (const message of demoMessages.value) {
      for (const attachment of message.attachments ?? []) {
        if (attachment.url.startsWith('blob:')) {
          URL.revokeObjectURL(attachment.url);
        }
      }
    }
  }

  demoMessages.value = [];
}

// 接待请求统一走接待客户端，并把响应里的会话 token 回填到凭证。
async function callApi(
  method: 'GET' | 'POST',
  path: string,
  body?: unknown,
): Promise<ReceptionStateData> {
  if (!receptionClient) {
    throw new Error(t('接口返回格式异常'));
  }

  const next = await receptionClient.request<ReceptionStateData>(
    method,
    path,
    body,
  );
  credentials?.rememberSessionToken(next.session_token);

  return next;
}

// 访客环境头承载 locale/timezone，入口形态与业务 query 参数由接待客户端按凭证注入。
function resolveEnvironmentHeaders(): Record<string, string> {
  const headers: Record<string, string> = {};
  const visitorLocale = resolveVisitorLocale();
  const visitorTimezone = resolveVisitorTimezone();

  if (visitorLocale) {
    headers['X-Helmdesk-Visitor-Locale'] = visitorLocale;
  }
  if (visitorTimezone) {
    headers['X-Helmdesk-Visitor-Timezone'] = visitorTimezone;
  }

  return headers;
}

function resolveVisitorLocale(): string | null {
  if (typeof navigator !== 'undefined') {
    const browserLocale = [
      ...(navigator.languages ?? []),
      navigator.language,
    ].find((value): value is string => Boolean(value?.trim()));

    if (browserLocale) {
      return browserLocale;
    }
  }

  if (typeof window !== 'undefined') {
    const storedLocale = window.localStorage.getItem(
      STANDALONE_LOCALE_STORAGE_KEY,
    );
    if (isSupportedLocale(storedLocale)) {
      return storedLocale;
    }
  }

  return locale.value || null;
}

function resolveVisitorTimezone(): string | null {
  return Intl.DateTimeFormat().resolvedOptions().timeZone || null;
}

async function loadState(): Promise<void> {
  if (!props.interactive) {
    return;
  }

  loading.value = true;
  errorMessage.value = null;
  try {
    await waitForInitialWidgetHostContext();
    state.value = await callApi(
      'GET',
      `/api/chat/${encodeURIComponent(props.channel.code)}/state`,
    );
    pausedWithoutSession.value = false;
    await scrollToBottom();
  } catch (err) {
    // 410 = 渠道已暂停且当前访客没有可恢复的会话；展示 paused 占位而不是错误。
    if (
      props.channel.paused &&
      typeof err === 'object' &&
      err !== null &&
      (err as { status?: number }).status === 410
    ) {
      pausedWithoutSession.value = true;
      return;
    }
    errorMessage.value = resolveAttachmentUploadError(err, t);
  } finally {
    loading.value = false;
  }
}

async function waitForInitialWidgetHostContext(): Promise<void> {
  if (
    props.entryMode !== 'widget' ||
    !widgetHostBridge ||
    widgetHostBridge.hostContext.value !== null
  ) {
    return;
  }

  await new Promise<void>((resolve) => {
    let stop: WatchStopHandle | null = null;
    const timer = window.setTimeout(() => {
      stop?.();
      resolve();
    }, WIDGET_HOST_CONTEXT_WAIT_MS);

    stop = watch(
      () => widgetHostBridge.hostContext.value,
      (value) => {
        if (value === null) {
          return;
        }

        window.clearTimeout(timer);
        stop?.();
        resolve();
      },
      { flush: 'sync' },
    );
  });
}

async function applyRealtimeState(
  nextState: Omit<ReceptionStateData, 'session_token'>,
): Promise<void> {
  errorMessage.value = null;
  state.value = {
    ...nextState,
    session_token: currentSessionToken.value,
  };
  await scrollToBottom();
}

async function sendMessage(): Promise<void> {
  if (!canSend.value) {
    return;
  }

  const content = composerValue.value.trim();

  // 演示模式：本地回显访客消息并给一条示例回复，纯前端、不发后端。
  if (props.demo) {
    appendDemoMessage('visitor', content);
    composerValue.value = '';
    clearComposerQuote();
    await scrollToBottom();
    await focusComposer();
    window.setTimeout(() => {
      appendDemoMessage('ai', t('这是一条预览示例回复，仅用于查看气泡样式。'));
      void scrollToBottom();
    }, 400);

    return;
  }

  sending.value = true;
  errorMessage.value = null;
  try {
    await postMessage(content, []);
    composerValue.value = '';
    clearComposerQuote();
    await scrollToBottom();
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : String(err);
  } finally {
    sending.value = false;
    await focusComposer();
  }
}

// 访客「正在输入」信号上报节流：连续输入期间最多每 2.5s 发一帧，远低于每次按键。
// Go 侧据此推迟聚合 flush，让访客一句话拆几段连发时 AI 等打完再回，而不是逐句作答。
const TYPING_NOTIFY_THROTTLE_MS = 2500;
let lastTypingNotifiedAt = 0;

// handleComposerInput 在访客实际输入时按节流上报 typing 信号（仅交互态、非演示模式）。
// @input 仅由真实输入触发，发送后的程序化清空不会误报。
function handleComposerInput(): void {
  if (props.demo || !props.interactive || !receptionClient) {
    return;
  }
  if (composerValue.value.trim() === '') {
    return;
  }

  const now = Date.now();
  if (now - lastTypingNotifiedAt < TYPING_NOTIFY_THROTTLE_MS) {
    return;
  }
  lastTypingNotifiedAt = now;

  void receptionClient.notifyTyping(
    `/api/chat/${encodeURIComponent(props.channel.code)}/typing`,
  );
}

function generateClientMsgId(): string {
  return crypto.randomUUID();
}

async function postMessage(
  content: string,
  attachmentIds: string[],
): Promise<void> {
  state.value = await callApi(
    'POST',
    `/api/chat/${encodeURIComponent(props.channel.code)}/messages`,
    {
      content,
      attachment_ids: attachmentIds,
      client_msg_id: generateClientMsgId(),
      quoted_message_id: composerQuote.value?.id ?? null,
    },
  );
}

async function recallMessage(messageId: string): Promise<void> {
  if (!messageId) {
    return;
  }
  // 二次校验：菜单可能悬停了一段时间才点；以点击瞬间的时间为准重新核对 2 分钟窗口。
  const target = messages.value.find((item) => item.id === messageId);
  if (target && !isMessageRecallable(target, Date.now())) {
    return;
  }

  errorMessage.value = null;
  try {
    state.value = await callApi(
      'POST',
      `/api/chat/${encodeURIComponent(props.channel.code)}/messages/${encodeURIComponent(messageId)}/recall`,
    );
    await scrollToBottom();
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : String(err);
  }
}

// 撤回 2 分钟时效窗口。不维护实时时钟：菜单打开时取一次时间快照即可，
// 多条消息共用同一个 ref（同时只会有一个右键菜单展开）。
const RECALL_WINDOW_MS = 2 * 60 * 1000;
const contextMenuOpenedAt = ref<number>(0);

function messageCreatedMs(message: ReceptionMessageData): number | null {
  const ts = Date.parse(message.created_at);

  return Number.isNaN(ts) ? null : ts;
}

function isMessageRecallable(
  message: ReceptionMessageData,
  referenceNow: number = Date.now(),
): boolean {
  if (!props.interactive) {
    return false;
  }
  if (!isVisitorMessage(message.role)) {
    return false;
  }
  if (message.recalled_at) {
    return false;
  }
  const created = messageCreatedMs(message);
  if (created === null) {
    return false;
  }

  return referenceNow - created <= RECALL_WINDOW_MS;
}

function handleContextMenuOpenChange(open: boolean): void {
  if (open) {
    contextMenuOpenedAt.value = Date.now();
  }
}

function canCopyMessage(message: ReceptionMessageData): boolean {
  return (
    !message.recalled_at &&
    typeof message.content === 'string' &&
    message.content.length > 0
  );
}

async function copyMessageContent(
  message: ReceptionMessageData,
): Promise<void> {
  if (!canCopyMessage(message)) {
    return;
  }
  try {
    await navigator.clipboard.writeText(message.content);
  } catch {
    return;
  }
}

function quoteMessage(message: ReceptionMessageData): void {
  if (message.recalled_at) {
    return;
  }

  composerQuote.value = {
    id: message.id,
    senderName: senderLabel(message),
    preview: quotePreview(message),
    content: message.content,
    attachments: message.attachments ?? [],
  };
  void focusComposer();
}

function clearComposerQuote(): void {
  composerQuote.value = null;
}

function quotePreview(message: ReceptionMessageData): string {
  if (
    typeof message.content === 'string' &&
    message.content.trim().length > 0
  ) {
    return message.content.replace(/\s+/g, ' ').slice(0, 120);
  }
  if (message.kind === 'image') {
    return t('图片');
  }
  if (message.kind === 'file') {
    return t('文件');
  }

  return t('无内容');
}

type QuotedMessage = NonNullable<ReceptionMessageData['quoted_message']>;
type ReceptionAttachment = ReceptionMessageData['attachments'][number];

function normalizeQuotedAttachments(value: unknown): ReceptionAttachment[] {
  if (Array.isArray(value)) {
    return value as ReceptionAttachment[];
  }
  if (value && typeof value === 'object') {
    return Object.values(value) as ReceptionAttachment[];
  }

  return [];
}

function quotedFullContent(
  quoted: QuotedMessage | ComposerQuoteTarget,
): string {
  const content = quoted.content?.trim();
  if (content) {
    return content;
  }

  return quoted.preview || t('无内容');
}

function quotedImageAttachment(
  quoted: QuotedMessage | ComposerQuoteTarget,
): ReceptionAttachment | null {
  return (
    normalizeQuotedAttachments(quoted.attachments).find((attachment) =>
      attachment.mime_type.startsWith('image/'),
    ) ?? null
  );
}

function quotedFileAttachment(
  quoted: QuotedMessage | ComposerQuoteTarget,
): ReceptionAttachment | null {
  return (
    normalizeQuotedAttachments(quoted.attachments).find(
      (attachment) => !attachment.mime_type.startsWith('image/'),
    ) ?? null
  );
}

function openQuotedImage(image: ReceptionAttachment): void {
  quotedPreviewImages.value = [image];
  quotedPreviewInitialId.value = image.id;
  quotedPreviewOpen.value = true;
}

function openQuotedFile(file: ReceptionAttachment): void {
  window.open(file.url, '_blank', 'noopener,noreferrer');
}

// 图片走 ImagePreviewDialog、文件走新标签打开、纯文本走居中模态框。
// 移动端为主的 C 端不再用 Popover——指向尖头的 CSS 在 reka-ui Portal 上下文里
// 反复出现颜色/渲染异常，模态框在小屏上点击区域也更友好。
function openQuotedTarget(quoted: QuotedMessage | ComposerQuoteTarget): void {
  const image = quotedImageAttachment(quoted);
  if (image) {
    openQuotedImage(image);
    return;
  }
  const file = quotedFileAttachment(quoted);
  if (file) {
    openQuotedFile(file);
    return;
  }
  quotedTextDialogContent.value = quotedFullContent(quoted);
  quotedTextDialogOpen.value = true;
}

function reeditRecalledMessage(message: ReceptionMessageData): void {
  const content = message.recalled_content;
  if (typeof content !== 'string' || content.length === 0) {
    return;
  }
  const existing = composerValue.value;
  composerValue.value =
    existing.length === 0
      ? content
      : existing.endsWith('\n')
        ? existing + content
        : `${existing}\n${content}`;
  void nextTick().then(() => {
    void focusComposer();
  });
}

async function handleFileChange(event: Event): Promise<void> {
  const target = event.target as HTMLInputElement;
  const files = Array.from(target.files ?? []);
  target.value = '';

  await uploadAndSendAttachments(files, 'conversation_file', 'file');
}

async function handleImageChange(event: Event): Promise<void> {
  const target = event.target as HTMLInputElement;
  const files = Array.from(target.files ?? []);
  target.value = '';

  await uploadAndSendAttachments(files, 'conversation_image', 'image');
}

function validateAttachmentFiles(files: File[]): boolean {
  if (files.length === 0) {
    return false;
  }

  if (files.length > MAX_ATTACHMENT_COUNT) {
    errorMessage.value = t('一次最多发送 {count} 个附件', {
      count: MAX_ATTACHMENT_COUNT,
    });

    return false;
  }

  return true;
}

async function uploadAndSendAttachments(
  files: File[],
  purpose: AttachmentPurpose,
  kind: 'file' | 'image',
): Promise<void> {
  if (isComposerActionDisabled.value && !attachmentUploading.value) {
    return;
  }
  if (!validateAttachmentFiles(files)) return;

  // 演示模式：本地回显附件消息并给一条示例回复，纯前端、不发后端。
  if (props.demo) {
    appendDemoMessage('visitor', '', {
      kind,
      attachments: buildDemoAttachments(files, kind),
    });
    clearComposerQuote();
    await scrollToBottom();
    await focusComposer();
    window.setTimeout(() => {
      appendDemoMessage('ai', t('这是一条预览示例回复，仅用于查看气泡样式。'));
      void scrollToBottom();
    }, 400);

    return;
  }

  let sessionToken: string;
  try {
    sessionToken = await ensureSessionState();
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : String(err);

    return;
  }

  const conversationId = state.value?.conversation_id;
  if (!conversationId) {
    errorMessage.value = t('会话尚未准备好，请稍后重试');

    return;
  }

  const pendingUpload = createPendingUpload(conversationId, files, kind);
  pendingUploads.value.push(pendingUpload);

  attachmentUploading.value = true;
  errorMessage.value = null;
  void scrollToBottom();

  try {
    const uploadedAttachmentIds: string[] = [];

    for (const [index, file] of files.entries()) {
      const pendingAttachment = pendingUpload.attachments[index];
      const attachment = await upload(file, {
        purpose,
        scope: 'visitor',
        context: {
          channel_code: props.channel.code,
        },
        visitorToken: sessionToken,
        onProgress: (value) => {
          pendingAttachment.progress = Math.min(100, Math.max(0, value));
        },
      });

      pendingAttachment.name = attachment.name;
      pendingAttachment.byteSize = attachment.byte_size;
      pendingAttachment.progress = 100;
      pendingAttachment.status = 'uploaded';
      uploadedAttachmentIds.push(attachment.id);
    }

    attachmentUploading.value = false;
    sending.value = true;
    try {
      await postMessage('', uploadedAttachmentIds);
      clearComposerQuote();
      removePendingUpload(pendingUpload.id);
      await scrollToBottom();
    } catch (err) {
      markPendingUploadFailed(pendingUpload.id, t('发送失败'), true);
      errorMessage.value = err instanceof Error ? err.message : String(err);
    } finally {
      sending.value = false;
      await focusComposer();
    }
  } catch (err) {
    errorMessage.value = resolveAttachmentUploadError(
      err,
      t,
      kind === 'image' ? '图片上传失败' : '附件上传失败',
    );
    markPendingUploadFailed(pendingUpload.id, t('上传失败'));
  } finally {
    attachmentUploading.value = false;
  }
}

function createPendingUpload(
  conversationId: string,
  files: File[],
  kind: 'file' | 'image',
): PendingComposerUpload {
  const uploadId = `composer-upload-${Date.now()}-${pendingUploadSequence++}`;

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

function markPendingUploadFailed(
  pendingUploadId: string,
  label: string,
  includeUploaded = false,
): void {
  const pendingUpload = pendingUploads.value.find(
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

function removePendingUpload(pendingUploadId: string): void {
  const pendingUpload = pendingUploads.value.find(
    (upload) => upload.id === pendingUploadId,
  );
  if (pendingUpload) {
    revokePendingUploadPreviews(pendingUpload);
  }

  pendingUploads.value = pendingUploads.value.filter(
    (upload) => upload.id !== pendingUploadId,
  );
}

function clearPendingUploads(): void {
  for (const pendingUpload of pendingUploads.value) {
    revokePendingUploadPreviews(pendingUpload);
  }

  pendingUploads.value = [];
}

function revokePendingUploadPreviews(
  pendingUpload: PendingComposerUpload,
): void {
  if (typeof URL === 'undefined') return;

  for (const attachment of pendingUpload.attachments) {
    if (attachment.previewUrl?.startsWith('blob:')) {
      URL.revokeObjectURL(attachment.previewUrl);
    }
  }
}

function pendingAttachmentStatusLabel(
  attachment: PendingComposerAttachment,
): string {
  if (attachment.status === 'failed') {
    return attachment.statusLabel ?? t('上传失败');
  }

  return `${attachment.progress}%`;
}

async function ensureSessionState(): Promise<string> {
  if (currentSessionToken.value !== '') {
    return currentSessionToken.value;
  }

  await loadState();

  if (currentSessionToken.value !== '') {
    return currentSessionToken.value;
  }

  throw new Error(t('会话尚未准备好，请稍后重试'));
}

function handleComposerPaste(event: ClipboardEvent): void {
  if (isComposerActionDisabled.value) return;

  const imageFiles = pastedImageFiles(event);
  if (imageFiles.length === 0) return;

  event.preventDefault();
  void uploadAndSendAttachments(imageFiles, 'conversation_image', 'image');
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

async function insertEmoji(emoji: string): Promise<void> {
  if (!canInteract.value) return;

  const composer = composerEl.value;
  const start = composer?.selectionStart ?? composerValue.value.length;
  const end = composer?.selectionEnd ?? composerValue.value.length;

  composerValue.value = [
    composerValue.value.slice(0, start),
    emoji,
    composerValue.value.slice(end),
  ].join('');
  emojiPopoverOpen.value = false;

  await nextTick();

  const nextCursor = start + emoji.length;
  composerEl.value?.focus({ preventScroll: true });
  composerEl.value?.setSelectionRange(nextCursor, nextCursor);
}

async function sendSuggestedQuestion(question: string): Promise<void> {
  if (isComposerActionDisabled.value) {
    return;
  }

  composerValue.value = question;
  await nextTick();
  await sendMessage();
}

async function focusComposer(): Promise<void> {
  if (!canInteract.value) {
    return;
  }

  await nextTick();

  // 右键 ContextMenu 关闭时会把焦点 restore 回 trigger 元素，要等下一帧才能抢回到 textarea。
  if (typeof window === 'undefined') {
    composerEl.value?.focus({ preventScroll: true });
    return;
  }

  window.requestAnimationFrame(() => {
    composerEl.value?.focus({ preventScroll: true });
  });
}

function handleComposerKeydown(event: KeyboardEvent): void {
  // Shift+Enter 换行；输入法组词期间的 Enter 用于上屏候选词。
  if (event.key !== 'Enter' || event.shiftKey || event.isComposing) {
    return;
  }

  event.preventDefault();
  void sendMessage();
}

async function scrollToBottom(): Promise<void> {
  await nextTick();
  const el = messageListEl.value;
  if (!el) {
    return;
  }

  el.scrollTop = el.scrollHeight;
}

function formatDateTime(iso: string): string {
  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) {
    throw new Error(`Invalid message timestamp: ${iso}`);
  }

  return date.toLocaleTimeString(undefined, {
    hour: '2-digit',
    minute: '2-digit',
  });
}

function isVisitorMessage(role: string): boolean {
  return role === 'visitor';
}

// 仅附件、没有正文文字的消息走"裸卡片"展示，去掉气泡背景和内边距，
// 让附件本身的卡片样式直接作为视觉边界——和 B 端的 isAttachmentOnly 一致。
function isAttachmentOnlyMessage(message: ReceptionMessageData): boolean {
  const hasAttachments = (message.attachments?.length ?? 0) > 0;
  const hasContent =
    typeof message.content === 'string' && message.content.length > 0;

  return hasAttachments && !hasContent;
}

const VISITOR_BUBBLE_CLASS =
  'max-w-full min-w-0 rounded-2xl rounded-tr-sm bg-[var(--standalone-visitor-bubble)] px-4 py-3 text-left text-sm leading-relaxed text-[var(--standalone-visitor-text)]';
const ASSISTANT_BUBBLE_CLASS =
  'max-w-full min-w-0 rounded-2xl rounded-tl-sm bg-[var(--standalone-assistant-bubble)] px-4 py-3 text-sm leading-relaxed text-[var(--standalone-assistant-text)]';

function visitorBubbleClass(message: ReceptionMessageData): string {
  return isAttachmentOnlyMessage(message)
    ? 'max-w-full min-w-0'
    : VISITOR_BUBBLE_CLASS;
}

function assistantBubbleClass(message: ReceptionMessageData): string {
  return isAttachmentOnlyMessage(message)
    ? 'max-w-full min-w-0'
    : ASSISTANT_BUBBLE_CLASS;
}

function senderLabel(message: ReceptionMessageData): string {
  if (message.sender_name) {
    return message.sender_name;
  }

  if (message.role === 'ai') {
    return props.channel.assistant_name;
  }

  if (message.role === 'teammate') {
    return t('客服');
  }

  if (message.role === 'visitor') {
    return t('我');
  }

  return '';
}

watch(
  () => props.interactive,
  (next) => {
    if (next) {
      void loadState();
    }

    if (!next) {
      closeConversationEventSource();
    }
  },
);

function closeConversationEventSource(): void {
  if (conversationEventSource) {
    conversationEventSource.close();
    conversationEventSource = null;
  }
  subscribedConversationId = null;
}

function subscribeConversationRealtime(conversationId: string): void {
  if (!props.interactive || subscribedConversationId === conversationId) {
    return;
  }

  closeConversationEventSource();
  subscribedConversationId = conversationId;

  const source = openMercureEventSource(
    receptionConversationTopic(conversationId),
  );
  conversationEventSource = source;

  source.addEventListener('reception', (event) => {
    try {
      const payload = JSON.parse((event as MessageEvent).data) as {
        conversation_id?: string;
        state?: Omit<ReceptionStateData, 'session_token'>;
      };

      if (payload.conversation_id !== state.value?.conversation_id) {
        return;
      }

      if (!payload.state) {
        throw new Error(t('实时消息格式异常'));
      }

      void applyRealtimeState(payload.state);
    } catch (error) {
      console.error('Standalone chat realtime payload is invalid.', error);
      errorMessage.value =
        error instanceof Error ? error.message : String(error);
    }
  });
}

watch(
  () => state.value?.conversation_id ?? null,
  (conversationId) => {
    clearComposerQuote();

    if (conversationId) {
      subscribeConversationRealtime(conversationId);
    } else {
      closeConversationEventSource();
    }
  },
);

// 仅在嵌入 widget 时，把"访客没看见过的非访客消息"折算成未读和 toast 推给宿主页：
//   - 首次加载视为已读，避免刷新页面闪一堆 toast；
//   - host 端报告 visible=true 时清零未读；
//   - 新到达的非访客消息 → 累计未读 + 用最近一条做 toast。
watch(
  [
    () => state.value?.messages ?? [],
    () => widgetHostBridge?.hostVisible.value ?? null,
  ],
  ([nextMessages, visible]) => {
    if (!widgetHostBridge) {
      return;
    }

    const nonVisitor = nextMessages.filter(
      (message) => message.role !== 'visitor',
    );
    const nonVisitorIds = nonVisitor.map((message) => message.id);

    if (!widgetUnreadInitialized) {
      widgetUnreadInitialized = true;
      nonVisitorIds.forEach((id) => seenNonVisitorMessageIds.add(id));
      widgetHostBridge.sendToHost('helmdesk:widget:unread', { count: 0 });
      return;
    }

    if (visible === true) {
      nonVisitorIds.forEach((id) => seenNonVisitorMessageIds.add(id));
      widgetHostBridge.sendToHost('helmdesk:widget:unread', { count: 0 });
      return;
    }

    const newlyArrived = nonVisitor.filter(
      (message) => !seenNonVisitorMessageIds.has(message.id),
    );
    if (newlyArrived.length === 0) {
      return;
    }

    const unreadCount = nonVisitor.length - seenNonVisitorMessageIds.size;
    widgetHostBridge.sendToHost('helmdesk:widget:unread', {
      count: Math.max(unreadCount, newlyArrived.length),
    });

    const latest = newlyArrived[newlyArrived.length - 1];
    widgetHostBridge.sendToHost('helmdesk:widget:toast', {
      text: latest.content ?? '',
      kind: latest.kind,
      sender_name: latest.sender_name,
      message_id: latest.id,
    });
  },
);

watch(
  () => widgetHostBridge?.shutdownRequested.value ?? false,
  (requested) => {
    if (requested) {
      closeConversationEventSource();
      clearPendingUploads();
    }
  },
);

onMounted(() => {
  if (props.interactive) {
    void loadState();
  }
});

onUnmounted(() => {
  closeConversationEventSource();
  clearPendingUploads();
  clearDemoMessages();
});
</script>

<template>
  <div
    class="flex h-full min-h-0 w-full flex-col overflow-hidden text-foreground"
    :style="pageStyle"
  >
    <ChannelPausedNotice v-if="pausedWithoutSession" :channel="props.channel" />
    <template v-else>
      <!-- 首页态：品牌欢迎屏 + 续聊卡片 + 进入聊天 CTA（参考 Salesmartly 欢迎屏） -->
      <div
        v-if="activeView === 'home'"
        class="flex min-h-0 flex-1 flex-col overflow-y-auto px-6 py-8 sm:px-8"
      >
        <div v-if="showWidgetCloseButton" class="mb-2 flex justify-end">
          <button
            type="button"
            :aria-label="t('关闭聊天')"
            :title="t('关闭聊天')"
            class="inline-flex size-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-white/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
            @click="requestWidgetClose"
          >
            <X class="size-4" />
          </button>
        </div>

        <div class="flex items-center gap-3">
          <Avatar class="size-9">
            <AvatarImage
              v-if="props.channel.icon_url"
              :src="props.channel.icon_url"
              :alt="props.channel.site_name"
            />
            <AvatarFallback
              class="bg-[var(--standalone-primary)] text-sm font-semibold text-[var(--standalone-visitor-text)]"
            >
              {{ props.channel.site_name.slice(0, 1).toUpperCase() }}
            </AvatarFallback>
          </Avatar>
          <span class="truncate text-lg font-semibold text-foreground">
            {{ props.channel.site_name }}
          </span>
        </div>

        <h2
          v-if="homeWelcomeMessage"
          class="mt-8 text-3xl leading-snug font-bold whitespace-pre-line text-foreground"
        >
          {{ homeWelcomeMessage }}
        </h2>

        <div
          class="mt-8 rounded-2xl bg-white/90 p-4 shadow-[0_18px_40px_-20px_rgba(15,23,42,0.35)] backdrop-blur"
        >
          <div class="flex items-center gap-3">
            <Avatar class="size-10">
              <AvatarImage
                v-if="props.channel.assistant_avatar_url"
                :src="props.channel.assistant_avatar_url"
                :alt="props.channel.assistant_name"
              />
              <AvatarImage
                v-else-if="props.channel.icon_url"
                :src="props.channel.icon_url"
                :alt="props.channel.site_name"
              />
              <AvatarFallback
                class="bg-[var(--standalone-primary)]/10 text-sm font-semibold text-[var(--standalone-primary)]"
              >
                {{ avatarFallback }}
              </AvatarFallback>
            </Avatar>
            <div class="min-w-0 flex-1">
              <div class="truncate text-sm font-medium text-foreground">
                {{ props.channel.site_name }}
              </div>
              <div
                v-if="homeCardHint"
                class="truncate text-xs text-muted-foreground"
              >
                {{ homeCardHint }}
              </div>
            </div>
          </div>
          <button
            type="button"
            class="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--standalone-primary)] px-4 py-3 text-sm font-medium text-[var(--standalone-visitor-text)] transition-opacity hover:opacity-90 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
            @click="enterChat"
          >
            <MessageCircle class="size-4" />
            {{ t('进入聊天') }}
          </button>
        </div>

        <div class="mt-auto pt-8 text-center text-xs text-muted-foreground/70">
          {{ t('由 HelmDesk 提供技术支持') }}
        </div>
      </div>

      <!-- 聊天线程态 -->
      <template v-else>
        <header
          v-if="showThreadHeader"
          class="relative flex shrink-0 items-center justify-center px-4 py-3 sm:px-6"
        >
          <button
            v-if="props.channel.home_mode_enabled"
            type="button"
            :aria-label="t('返回首页')"
            :title="t('返回首页')"
            class="absolute left-4 inline-flex size-9 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-white/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none sm:left-6"
            @click="backToHome"
          >
            <ArrowLeft class="size-5" />
          </button>

          <div
            class="flex max-w-[70%] items-center gap-2.5 rounded-full bg-white/80 px-4 py-2 shadow-[0_10px_30px_-16px_rgba(15,23,42,0.4)] backdrop-blur"
          >
            <Avatar class="size-8 shrink-0">
              <AvatarImage
                v-if="props.channel.icon_url"
                :src="props.channel.icon_url"
                :alt="props.channel.site_name"
              />
              <AvatarFallback
                class="bg-[var(--standalone-primary)]/10 text-xs font-semibold text-[var(--standalone-primary)]"
              >
                {{ props.channel.site_name.slice(0, 1).toUpperCase() }}
              </AvatarFallback>
            </Avatar>
            <div class="min-w-0 text-left">
              <div class="truncate text-sm font-semibold text-foreground">
                {{ props.channel.site_name }}
              </div>
              <p
                v-if="props.channel.subtitle"
                class="truncate text-xs text-muted-foreground"
              >
                {{ props.channel.subtitle }}
              </p>
            </div>
          </div>

          <button
            v-if="showWidgetCloseButton"
            type="button"
            :aria-label="t('关闭聊天')"
            :title="t('关闭聊天')"
            class="absolute right-4 inline-flex size-9 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-white/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none sm:right-6"
            @click="requestWidgetClose"
          >
            <X class="size-4" />
          </button>
        </header>

        <main class="flex min-h-0 flex-1 justify-center overflow-hidden">
          <div class="flex w-full flex-1 flex-col px-4 sm:px-6">
            <div
              ref="messageListEl"
              class="flex-1 [scrollbar-gutter:stable] space-y-4 overflow-y-auto py-6 pr-3"
            >
              <div
                v-if="showInlineGreeting && hasGreetingContent"
                class="flex items-start gap-3"
              >
                <Avatar class="mt-0.5 size-9 shrink-0">
                  <AvatarImage
                    v-if="props.channel.assistant_avatar_url"
                    :src="props.channel.assistant_avatar_url"
                    :alt="props.channel.assistant_name"
                  />
                  <AvatarFallback
                    class="bg-primary/10 text-[11px] font-semibold text-primary"
                  >
                    {{ avatarFallback }}
                  </AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1 space-y-1">
                  <div class="text-xs text-muted-foreground">
                    {{ props.channel.assistant_name }}
                  </div>
                  <div
                    class="rounded-2xl rounded-tl-sm bg-[var(--standalone-assistant-bubble)] px-4 py-3 text-sm leading-relaxed text-[var(--standalone-assistant-text)]"
                  >
                    <p
                      v-if="effectiveGreetingMessage"
                      class="whitespace-pre-line opacity-80"
                    >
                      {{ effectiveGreetingMessage }}
                    </p>
                  </div>
                </div>
              </div>

              <template v-for="message in messages" :key="message.id">
                <div
                  v-if="isVisitorMessage(message.role)"
                  class="flex w-full flex-col gap-1"
                >
                  <div class="mr-12 text-right text-xs text-muted-foreground">
                    {{ senderLabel(message) }}
                    <span class="ml-1">{{
                      formatDateTime(message.created_at)
                    }}</span>
                  </div>
                  <div class="flex w-full items-start justify-end gap-3">
                    <div
                      class="flex max-w-[80%] min-w-0 flex-col items-end gap-1"
                    >
                      <div
                        v-if="message.recalled_at"
                        class="flex flex-wrap items-baseline justify-end gap-2 rounded-2xl rounded-tr-sm border border-dashed border-muted-foreground/30 bg-muted/40 px-4 py-2 text-xs text-muted-foreground italic"
                      >
                        <span>{{ t('你撤回了一条消息') }}</span>
                        <button
                          v-if="message.recalled_content && props.interactive"
                          type="button"
                          class="text-primary not-italic hover:underline"
                          @click="reeditRecalledMessage(message)"
                        >
                          {{ t('重新编辑') }}
                        </button>
                      </div>
                      <ContextMenu
                        v-if="!message.recalled_at && props.interactive"
                        @update:open="handleContextMenuOpenChange"
                      >
                        <ContextMenuTrigger as-child>
                          <div :class="visitorBubbleClass(message)">
                            <p
                              v-if="message.content"
                              class="[overflow-wrap:anywhere] break-words whitespace-pre-wrap"
                            >
                              {{ message.content }}
                            </p>
                            <div
                              v-if="message.attachments?.length"
                              class="mt-2 space-y-2"
                            >
                              <StandaloneAttachmentCard
                                v-for="attachment in message.attachments"
                                :key="attachment.id"
                                :attachment="attachment"
                                variant="visitor"
                              />
                            </div>
                          </div>
                        </ContextMenuTrigger>
                        <ContextMenuContent class="w-28">
                          <ContextMenuItem
                            :disabled="!canCopyMessage(message)"
                            @select="copyMessageContent(message)"
                          >
                            {{ t('复制') }}
                          </ContextMenuItem>
                          <ContextMenuItem @select="quoteMessage(message)">
                            {{ t('引用') }}
                          </ContextMenuItem>
                          <ContextMenuItem
                            variant="destructive"
                            :disabled="
                              !isMessageRecallable(message, contextMenuOpenedAt)
                            "
                            @select="recallMessage(message.id)"
                          >
                            {{ t('撤回') }}
                          </ContextMenuItem>
                        </ContextMenuContent>
                      </ContextMenu>
                      <div
                        v-if="!message.recalled_at && !props.interactive"
                        :class="visitorBubbleClass(message)"
                      >
                        <p
                          v-if="message.content"
                          class="[overflow-wrap:anywhere] break-words whitespace-pre-wrap"
                        >
                          {{ message.content }}
                        </p>
                        <div
                          v-if="message.attachments?.length"
                          class="mt-2 space-y-2"
                        >
                          <StandaloneAttachmentCard
                            v-for="attachment in message.attachments"
                            :key="attachment.id"
                            :attachment="attachment"
                            variant="visitor"
                          />
                        </div>
                      </div>
                      <QuotedMessagePreview
                        v-if="message.quoted_message && !message.recalled_at"
                        :quoted="message.quoted_message"
                        side="visitor"
                        @open="openQuotedTarget(message.quoted_message)"
                      />
                    </div>
                    <Avatar class="size-9 shrink-0">
                      <AvatarImage
                        v-if="message.sender_avatar_url"
                        :src="message.sender_avatar_url"
                        :alt="senderLabel(message)"
                      />
                      <AvatarFallback
                        class="bg-muted text-[11px] font-semibold text-muted-foreground"
                      >
                        {{ senderLabel(message).slice(0, 1) || t('我') }}
                      </AvatarFallback>
                    </Avatar>
                  </div>
                </div>
                <div v-else class="flex w-full flex-col gap-1">
                  <div class="ml-12 text-xs text-muted-foreground">
                    {{ senderLabel(message) }}
                    <span class="ml-1">{{
                      formatDateTime(message.created_at)
                    }}</span>
                  </div>
                  <div class="flex w-full items-start gap-3">
                    <Avatar class="size-9 shrink-0">
                      <AvatarImage
                        v-if="message.sender_avatar_url"
                        :src="message.sender_avatar_url"
                        :alt="senderLabel(message)"
                      />
                      <AvatarFallback
                        class="bg-primary/10 text-[11px] font-semibold text-primary"
                      >
                        {{ avatarFallback }}
                      </AvatarFallback>
                    </Avatar>
                    <div
                      class="flex max-w-[80%] min-w-0 flex-col items-start gap-1"
                    >
                      <div
                        v-if="message.recalled_at"
                        class="rounded-2xl rounded-tl-sm border border-dashed border-muted-foreground/30 bg-muted/40 px-4 py-2 text-xs text-muted-foreground italic"
                      >
                        {{ t('对方撤回了一条消息') }}
                      </div>
                      <ContextMenu
                        v-if="!message.recalled_at && props.interactive"
                        @update:open="handleContextMenuOpenChange"
                      >
                        <ContextMenuTrigger as-child>
                          <div :class="assistantBubbleClass(message)">
                            <p
                              v-if="message.content"
                              class="[overflow-wrap:anywhere] break-words whitespace-pre-wrap"
                            >
                              {{ message.content }}
                            </p>
                            <div
                              v-if="message.attachments?.length"
                              class="mt-2 space-y-2"
                            >
                              <StandaloneAttachmentCard
                                v-for="attachment in message.attachments"
                                :key="attachment.id"
                                :attachment="attachment"
                                variant="assistant"
                              />
                            </div>
                          </div>
                        </ContextMenuTrigger>
                        <ContextMenuContent class="w-28">
                          <ContextMenuItem
                            :disabled="!canCopyMessage(message)"
                            @select="copyMessageContent(message)"
                          >
                            {{ t('复制') }}
                          </ContextMenuItem>
                          <ContextMenuItem @select="quoteMessage(message)">
                            {{ t('引用') }}
                          </ContextMenuItem>
                        </ContextMenuContent>
                      </ContextMenu>
                      <div
                        v-if="!message.recalled_at && !props.interactive"
                        :class="assistantBubbleClass(message)"
                      >
                        <p
                          v-if="message.content"
                          class="[overflow-wrap:anywhere] break-words whitespace-pre-wrap"
                        >
                          {{ message.content }}
                        </p>
                        <div
                          v-if="message.attachments?.length"
                          class="mt-2 space-y-2"
                        >
                          <StandaloneAttachmentCard
                            v-for="attachment in message.attachments"
                            :key="attachment.id"
                            :attachment="attachment"
                            variant="assistant"
                          />
                        </div>
                      </div>
                      <QuotedMessagePreview
                        v-if="message.quoted_message && !message.recalled_at"
                        :quoted="message.quoted_message"
                        side="assistant"
                        @open="openQuotedTarget(message.quoted_message)"
                      />
                    </div>
                  </div>
                </div>
              </template>

              <div
                v-if="props.interactive && loading && messages.length === 0"
                class="text-center text-xs text-muted-foreground"
              >
                {{ t('正在加载会话……') }}
              </div>
            </div>

            <div
              v-if="errorMessage"
              class="shrink-0 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
            >
              {{ errorMessage }}
            </div>

            <div class="shrink-0 pt-2 pb-4 sm:pb-6">
              <div
                v-if="enabledSuggestionItems.length > 0"
                class="mb-3 flex flex-wrap justify-center gap-2"
              >
                <button
                  v-for="item in enabledSuggestionItems"
                  :key="item"
                  type="button"
                  :disabled="isComposerActionDisabled"
                  class="rounded-full border border-[var(--standalone-primary)]/25 bg-background/80 px-3 py-1.5 text-xs font-medium text-[var(--standalone-primary)] shadow-xs transition-colors hover:bg-background disabled:cursor-default disabled:opacity-50"
                  @click="sendSuggestedQuestion(item)"
                >
                  {{ item }}
                </button>
              </div>
              <div
                v-if="visiblePendingUploads.length > 0"
                class="mb-2 flex flex-wrap gap-2"
              >
                <template
                  v-for="pendingUpload in visiblePendingUploads"
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
                      <Paperclip
                        class="size-4 shrink-0 text-muted-foreground"
                      />
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
                      {{ pendingAttachmentStatusLabel(attachment) }}
                    </div>
                    <button
                      v-if="attachment.status === 'failed'"
                      type="button"
                      :title="t('移除')"
                      :aria-label="t('移除')"
                      class="absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full bg-destructive text-white shadow-sm"
                      @click="removePendingUpload(pendingUpload.id)"
                    >
                      <X class="size-3" />
                    </button>
                  </div>
                </template>
              </div>
              <div
                class="overflow-hidden rounded-2xl border border-input bg-background shadow-xs transition-[box-shadow,border-color] duration-200 focus-within:border-foreground/30 focus-within:shadow-[0_8px_28px_-6px_color-mix(in_oklab,var(--foreground)_30%,transparent)]"
              >
                <input
                  ref="fileInputEl"
                  type="file"
                  class="sr-only"
                  multiple
                  :disabled="isComposerActionDisabled"
                  @change="handleFileChange"
                />
                <input
                  ref="imageInputEl"
                  type="file"
                  class="sr-only"
                  multiple
                  accept="image/*"
                  :disabled="isComposerActionDisabled"
                  @change="handleImageChange"
                />
                <div class="relative">
                  <textarea
                    ref="composerEl"
                    v-model="composerValue"
                    :disabled="!canInteract || sending"
                    :maxlength="MAX_CONTENT_LENGTH"
                    class="block h-28 w-full resize-none overflow-y-auto bg-transparent px-4 pt-3 pb-6 text-sm leading-5 outline-none placeholder:text-muted-foreground disabled:cursor-default"
                    @input="handleComposerInput"
                    @keydown="handleComposerKeydown"
                    @paste="handleComposerPaste"
                  ></textarea>
                  <div
                    v-if="composerQuote"
                    class="absolute inset-x-4 bottom-1 flex items-center gap-2 text-xs text-muted-foreground"
                  >
                    <button
                      type="button"
                      class="flex min-w-0 flex-1 items-center text-left transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                      @click="openQuotedTarget(composerQuote)"
                    >
                      <span class="max-w-[45%] shrink-0 truncate font-medium">
                        {{ composerQuote.senderName }}：
                      </span>
                      <span class="min-w-0 truncate">
                        {{ composerQuote.preview }}
                      </span>
                    </button>
                    <button
                      type="button"
                      class="inline-flex size-5 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                      :aria-label="t('取消引用')"
                      :title="t('取消引用')"
                      @click="clearComposerQuote"
                    >
                      <X class="size-3.5" />
                    </button>
                  </div>
                </div>
                <div
                  class="flex items-center justify-between gap-2 px-2.5 pb-2"
                >
                  <div class="flex items-center gap-1">
                    <Popover v-model:open="emojiPopoverOpen">
                      <PopoverTrigger as-child>
                        <button
                          type="button"
                          :disabled="isComposerActionDisabled"
                          :aria-label="t('选择表情')"
                          :title="t('选择表情')"
                          class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-default disabled:opacity-40"
                        >
                          <Smile class="size-4" />
                        </button>
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
                              @click="insertEmoji(emoji)"
                            >
                              {{ emoji }}
                            </button>
                          </div>
                        </div>
                      </PopoverContent>
                    </Popover>
                    <button
                      type="button"
                      :disabled="isComposerActionDisabled"
                      :aria-label="t('添加附件')"
                      :title="t('添加附件')"
                      class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-default disabled:opacity-40"
                      @click="fileInputEl?.click()"
                    >
                      <Paperclip class="size-4" />
                    </button>
                    <button
                      type="button"
                      :disabled="isComposerActionDisabled"
                      :aria-label="t('添加图片')"
                      :title="t('添加图片')"
                      class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-default disabled:opacity-40"
                      @click="imageInputEl?.click()"
                    >
                      <ImageIcon class="size-4" />
                    </button>
                  </div>
                  <button
                    type="button"
                    :disabled="!canSend"
                    class="inline-flex h-9 shrink-0 items-center justify-center rounded-lg bg-[var(--standalone-primary)] px-3 text-sm font-medium text-[var(--standalone-visitor-text)] transition-opacity disabled:cursor-default disabled:opacity-40"
                    @click="sendMessage"
                  >
                    <ArrowUp class="size-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </main>

        <ImagePreviewDialog
          v-if="quotedPreviewImages.length"
          v-model:open="quotedPreviewOpen"
          :images="quotedPreviewImages"
          :initial-id="quotedPreviewInitialId"
        />
        <Dialog v-model:open="quotedTextDialogOpen">
          <DialogContent
            class="max-h-[80vh] overflow-x-hidden overflow-y-auto sm:max-w-md"
          >
            <DialogHeader>
              <!-- 用户要求弹窗内只显示消息内容，不显示发件人。标题用 sr-only 保留可访问性。 -->
              <DialogTitle class="sr-only">{{ t('引用消息内容') }}</DialogTitle>
            </DialogHeader>
            <div
              class="min-w-0 text-sm leading-6 [overflow-wrap:anywhere] whitespace-pre-wrap"
            >
              {{ quotedTextDialogContent }}
            </div>
          </DialogContent>
        </Dialog>
      </template>
    </template>
  </div>
</template>
