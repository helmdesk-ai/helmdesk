<!--
  文件说明：AI 助手浮动层组件，支持拖动、最小化，提供 AI 对话输入与流式回复展示。
-->
<script setup lang="ts">
import SendAiAssistantMessageAction from '@/actions/App/Actions/AiChat/SendAiAssistantMessageAction';
import StopAiAssistantMessageAction from '@/actions/App/Actions/AiChat/StopAiAssistantMessageAction';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { useRequiredSystem } from '@/composables/useSystemContext';
import { renderMarkdownToSafeHtml } from '@/lib/markdown';
import type { AppPageProps } from '@/types';
import type { AiModelOptionData } from '@/types/generated';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
  CheckCircle2,
  ChevronRight,
  Loader2,
  Send,
  Square,
  Wrench,
  X,
} from '@lucide/vue';
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from 'vue';

// 文本消息和工具消息分开建模，方便流式插入 tool_call / tool_result 节点。
interface TextMessage {
  id: string;
  kind: 'text';
  role: 'user' | 'assistant';
  content: string;
  pending?: boolean;
  error?: string;
}

interface ToolMessage {
  id: string;
  kind: 'tool_call' | 'tool_result';
  // tool 是 LLM 看到的、经过 sanitize 的工具名（fallback / 排查用）。
  tool: string;
  // display 是 "MCP 服务名 / 工具原名" 这种人类可读标签，Go 侧仅对 MCP 工具下发。
  display?: string;
  detail: string;
  expanded: boolean;
}

type ChatMessage = TextMessage | ToolMessage;

interface StreamPayload {
  type?: 'delta' | 'tool_call' | 'tool_result' | 'done' | 'error';
  content?: string;
  error?: string;
  tool?: string;
  tool_display?: string;
  args?: string;
}

interface StoredModelSelection {
  id: string;
  label: string;
  providerName: string;
  modelId: string;
}

interface StoredPosition {
  mode: 'docked' | 'floating';
  x: number;
  y: number;
}

const { t } = useI18n();
const system = useRequiredSystem();
const page = usePage<AppPageProps>();

const isOpen = ref(false);
const inputValue = ref('');
const textareaRef = ref<HTMLTextAreaElement | null>(null);
const messagesRef = ref<HTMLDivElement | null>(null);
const messages = ref<ChatMessage[]>([]);
const isStreaming = ref(false);
const isStopping = ref(false);
const currentTopic = ref<string | null>(null);
const selectedModelId = ref('');
const invalidStoredModelLabel = ref<string | null>(null);

// --- Positioning & drag state ---
const widgetRef = ref<HTMLDivElement | null>(null);
const mode = ref<'docked' | 'floating'>('docked');
const widgetPos = ref({ x: 0, y: 0 });
const isDragging = ref(false);
const dragOrigin = ref({
  x: 0,
  y: 0,
  posX: 0,
  posY: 0,
  mode: 'docked' as 'docked' | 'floating',
});
const hasDragged = ref(false);

const BUTTON_SIZE = 40;
const DOCKED_WIDTH = 40;
const SNAP_THRESHOLD = 80;
const DRAG_THRESHOLD = 5;
const EDGE_MARGIN = 8;

const positionStorageKey = computed(
  () => `ai-assistant:position:${system.value.id}`,
);

// 当前正在接收 delta 的 assistant 气泡。
let currentAssistantId: string | null = null;

// 当前流式订阅，切换轮次或卸载时关闭。
let currentEventSource: EventSource | null = null;

const hasMessages = computed(() => messages.value.length > 0);
const modelOptions = computed<AiModelOptionData[]>(() => {
  if (!Array.isArray(page.props.aiAssistantLlmModelOptions)) {
    throw new Error('aiAssistantLlmModelOptions is required.');
  }

  return page.props.aiAssistantLlmModelOptions;
});
const hasAvailableModels = computed(() => modelOptions.value.length > 0);
const hasSelectedModel = computed(() => selectedModelId.value.trim() !== '');
const modelStorageKey = computed(
  () => `ai-assistant:selected-model:${system.value.id}`,
);

const groupedModelOptions = computed(() => {
  const groups = new Map<string, AiModelOptionData[]>();
  for (const option of modelOptions.value) {
    const list = groups.get(option.provider_name) ?? [];
    list.push(option);
    groups.set(option.provider_name, list);
  }

  return Array.from(groups, ([providerName, options]) => ({
    providerName,
    options,
  }));
});

// --- Container positioning ---
// Anchor the container by its bottom edge so the button stays in place
// when the panel opens (the container grows upward, never shifts the button).
const windowHeight = ref(
  typeof window !== 'undefined' ? window.innerHeight : 900,
);
const windowWidth = ref(
  typeof window !== 'undefined' ? window.innerWidth : 1440,
);

const btnW = computed(() =>
  mode.value === 'docked' ? DOCKED_WIDTH : BUTTON_SIZE,
);

const containerStyle = computed(() => {
  const h = windowHeight.value;
  const bottomPx = h - widgetPos.value.y - BUTTON_SIZE;
  const w = btnW.value;
  if (mode.value === 'docked') {
    return {
      right: '0px',
      bottom: `${bottomPx}px`,
      left: 'auto',
      top: 'auto',
      width: `${w}px`,
      height: `${BUTTON_SIZE}px`,
      transform: 'none',
    };
  }
  return {
    left: `${widgetPos.value.x}px`,
    bottom: `${bottomPx}px`,
    right: 'auto',
    top: 'auto',
    width: `${w}px`,
    height: `${BUTTON_SIZE}px`,
    transform: 'none',
  };
});

// Panel is absolute-positioned relative to the button container and always
// slides out to the left of the button so docked and floating modes share
// the same panel placement.

// 限制上下文长度，避免单次请求带上过多历史消息。
const MAX_HISTORY_MESSAGES = 20;

const buildHistoryPayload = () =>
  messages.value
    .filter((m): m is TextMessage => m.kind === 'text')
    .filter((m) => !m.error && m.content.trim() !== '')
    .slice(-MAX_HISTORY_MESSAGES)
    .map((m) => ({ role: m.role, content: m.content }));

const scrollToBottom = () => {
  const el = messagesRef.value;
  if (!el) {
    return;
  }
  el.scrollTop = el.scrollHeight;
};

const createMessageId = () => {
  if (
    typeof globalThis.crypto !== 'undefined' &&
    typeof globalThis.crypto.randomUUID === 'function'
  ) {
    return globalThis.crypto.randomUUID();
  }
  return `msg-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
};

// --- Position persistence ---
const savePosition = () => {
  if (typeof window === 'undefined') return;
  const data: StoredPosition = {
    mode: mode.value,
    x: widgetPos.value.x,
    y: widgetPos.value.y,
  };
  window.localStorage.setItem(positionStorageKey.value, JSON.stringify(data));
};

const clampPosition = () => {
  const maxX = windowWidth.value - BUTTON_SIZE - EDGE_MARGIN;
  const maxY = windowHeight.value - BUTTON_SIZE - EDGE_MARGIN;
  widgetPos.value = {
    x: Math.max(EDGE_MARGIN, Math.min(maxX, widgetPos.value.x)),
    y: Math.max(EDGE_MARGIN, Math.min(maxY, widgetPos.value.y)),
  };
};

const defaultDockedY = () => windowHeight.value - BUTTON_SIZE - 16;

const initWidgetPos = () => {
  widgetPos.value = {
    x: windowWidth.value - BUTTON_SIZE,
    y: defaultDockedY(),
  };
};

const loadPosition = () => {
  if (typeof window === 'undefined') return;
  try {
    const raw = window.localStorage.getItem(positionStorageKey.value);
    if (!raw) {
      initWidgetPos();
      return;
    }
    const data = JSON.parse(raw) as StoredPosition;
    if (data.mode === 'docked') {
      mode.value = 'docked';
      widgetPos.value = {
        x: windowWidth.value - BUTTON_SIZE,
        y:
          typeof data.y === 'number'
            ? Math.max(
                EDGE_MARGIN,
                Math.min(
                  windowHeight.value - BUTTON_SIZE - EDGE_MARGIN,
                  data.y,
                ),
              )
            : defaultDockedY(),
      };
    } else if (
      data.mode === 'floating' &&
      typeof data.x === 'number' &&
      typeof data.y === 'number'
    ) {
      mode.value = 'floating';
      widgetPos.value = { x: data.x, y: data.y };
      clampPosition();
    } else {
      initWidgetPos();
    }
  } catch {
    initWidgetPos();
  }
};

// --- Drag handlers ---

const onPointerDown = (e: PointerEvent) => {
  const target = e.target as HTMLElement;
  if (
    target.closest('textarea') ||
    target.closest('select') ||
    target.closest('button:not([data-drag-handle])') ||
    target.closest('[role="listbox"]') ||
    target.closest('[role="option"]')
  ) {
    return;
  }

  isDragging.value = true;
  hasDragged.value = false;

  const currentTarget = e.currentTarget as HTMLElement;
  const rect = currentTarget.getBoundingClientRect();
  dragOrigin.value = {
    x: e.clientX,
    y: e.clientY,
    posX: rect.left,
    posY: rect.top,
    mode: mode.value,
  };

  e.preventDefault();
  currentTarget.setPointerCapture?.(e.pointerId);
};

const onPointerMove = (e: PointerEvent) => {
  if (!isDragging.value) return;

  const deltaX = e.clientX - dragOrigin.value.x;
  const deltaY = e.clientY - dragOrigin.value.y;
  const distance = Math.sqrt(deltaX * deltaX + deltaY * deltaY);

  if (distance < DRAG_THRESHOLD) return;
  hasDragged.value = true;

  const newX = dragOrigin.value.posX + deltaX;
  const newY = dragOrigin.value.posY + deltaY;

  if (dragOrigin.value.mode === 'docked') {
    // How far left the button has been pulled from the right edge
    const distFromRight = windowWidth.value - newX - BUTTON_SIZE;
    if (distFromRight > SNAP_THRESHOLD / 2) {
      // Pulled far enough left — switch to floating
      mode.value = 'floating';
      widgetPos.value = {
        x: Math.max(
          EDGE_MARGIN,
          Math.min(windowWidth.value - BUTTON_SIZE - EDGE_MARGIN, newX),
        ),
        y: Math.max(
          EDGE_MARGIN,
          Math.min(windowHeight.value - BUTTON_SIZE - EDGE_MARGIN, newY),
        ),
      };
    } else {
      // Stay docked — only track vertical movement along the right edge
      widgetPos.value = {
        x: windowWidth.value - BUTTON_SIZE,
        y: Math.max(
          EDGE_MARGIN,
          Math.min(windowHeight.value - BUTTON_SIZE - EDGE_MARGIN, newY),
        ),
      };
    }
  } else {
    widgetPos.value = {
      x: Math.max(
        EDGE_MARGIN,
        Math.min(windowWidth.value - BUTTON_SIZE - EDGE_MARGIN, newX),
      ),
      y: Math.max(
        EDGE_MARGIN,
        Math.min(windowHeight.value - BUTTON_SIZE - EDGE_MARGIN, newY),
      ),
    };
  }
};

const onPointerUp = () => {
  if (!isDragging.value) return;
  isDragging.value = false;

  if (!hasDragged.value) {
    return;
  }

  // Snap to right edge if close enough
  const distFromRight = windowWidth.value - widgetPos.value.x - BUTTON_SIZE;
  if (distFromRight < SNAP_THRESHOLD) {
    mode.value = 'docked';
    widgetPos.value = {
      x: windowWidth.value - BUTTON_SIZE,
      y: widgetPos.value.y,
    };
  }

  savePosition();
};

const onPointerCancel = () => {
  if (isDragging.value) {
    isDragging.value = false;
    if (hasDragged.value) {
      const distFromRight = windowWidth.value - widgetPos.value.x - BUTTON_SIZE;
      if (distFromRight < SNAP_THRESHOLD) {
        mode.value = 'docked';
        widgetPos.value = {
          x: windowWidth.value - BUTTON_SIZE,
          y: widgetPos.value.y,
        };
      }
      savePosition();
    }
  }
};

// --- Resize handler ---
const onWindowResize = () => {
  const prevWidth = windowWidth.value;
  const prevHeight = windowHeight.value;
  windowWidth.value = window.innerWidth;
  windowHeight.value = window.innerHeight;

  if (mode.value === 'docked') {
    // Keep docked to right edge, clamp Y to new viewport
    widgetPos.value = {
      x: windowWidth.value - BUTTON_SIZE,
      y: Math.max(
        EDGE_MARGIN,
        Math.min(
          windowHeight.value - BUTTON_SIZE - EDGE_MARGIN,
          widgetPos.value.y,
        ),
      ),
    };
  } else {
    // Shift proportionally for width changes, clamp to new bounds
    if (prevWidth > 0) {
      widgetPos.value = {
        x: Math.round((widgetPos.value.x / prevWidth) * windowWidth.value),
        y: Math.round((widgetPos.value.y / prevHeight) * windowHeight.value),
      };
    }
    clampPosition();
  }
  savePosition();
};

// --- Click handler (prevents click after drag) ---
const onButtonClick = () => {
  if (hasDragged.value) return;
  toggleOpen();
};

const parseStoredModelSelection = (
  raw: string | null,
): StoredModelSelection | null => {
  if (!raw) {
    return null;
  }

  const parsed = JSON.parse(raw) as Partial<StoredModelSelection>;
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
};

const clearStoredModelSelection = () => {
  if (typeof window === 'undefined') {
    return;
  }

  window.localStorage.removeItem(modelStorageKey.value);
};

const loadStoredModelSelection = (): StoredModelSelection | null => {
  if (typeof window === 'undefined') {
    return null;
  }

  try {
    return parseStoredModelSelection(
      window.localStorage.getItem(modelStorageKey.value),
    );
  } catch {
    clearStoredModelSelection();
    return null;
  }
};

const selectFirstAvailableModel = (): void => {
  const firstOption = modelOptions.value[0];
  if (!firstOption) {
    return;
  }

  selectedModelId.value = firstOption.value;
  invalidStoredModelLabel.value = null;
};

const storeSelectedModel = (modelId: string) => {
  if (typeof window === 'undefined') {
    return;
  }

  const option = modelOptions.value.find((item) => item.value === modelId);
  if (!option) {
    clearStoredModelSelection();
    return;
  }

  const payload: StoredModelSelection = {
    id: option.value,
    label: option.label,
    providerName: option.provider_name,
    modelId: option.model_id,
  };

  window.localStorage.setItem(modelStorageKey.value, JSON.stringify(payload));
};

const syncSelectedModelFromStorage = () => {
  if (typeof window === 'undefined') {
    return;
  }

  const currentSelection = selectedModelId.value.trim();
  if (currentSelection !== '') {
    const stillExists = modelOptions.value.some(
      (option) => option.value === currentSelection,
    );
    if (stillExists) {
      return;
    }

    const remembered = loadStoredModelSelection();
    invalidStoredModelLabel.value =
      remembered?.label ?? invalidStoredModelLabel.value ?? currentSelection;
    selectedModelId.value = '';
    clearStoredModelSelection();
    return;
  }

  const remembered = loadStoredModelSelection();
  if (!remembered) {
    selectFirstAvailableModel();
    return;
  }

  const matched = modelOptions.value.find(
    (option) => option.value === remembered.id,
  );

  if (!matched) {
    invalidStoredModelLabel.value = remembered.label;
    clearStoredModelSelection();
    return;
  }

  selectedModelId.value = matched.value;
  invalidStoredModelLabel.value = null;
};

const closeStream = () => {
  if (currentEventSource) {
    currentEventSource.close();
    currentEventSource = null;
  }
};

// Markdown 清洗在 renderMarkdownToSafeHtml 内完成。
const renderAssistantHtml = (message: TextMessage): string =>
  renderMarkdownToSafeHtml(message.content);

const findAssistantById = (id: string | null): TextMessage | undefined => {
  if (!id) return undefined;
  const msg = messages.value.find((m) => m.id === id);
  return msg && msg.kind === 'text' && msg.role === 'assistant'
    ? msg
    : undefined;
};

const openAssistantBubble = (): string => {
  const id = createMessageId();
  messages.value.push({
    id,
    kind: 'text',
    role: 'assistant',
    content: '',
    pending: true,
  });
  currentAssistantId = id;
  nextTick(() => scrollToBottom());
  return id;
};

// 工具调用前先收口当前气泡，避免留下空占位。
const sealCurrentAssistantBubble = () => {
  const msg = findAssistantById(currentAssistantId);
  currentAssistantId = null;
  if (!msg) return;

  if (!msg.content.trim()) {
    messages.value = messages.value.filter((m) => m.id !== msg.id);
    return;
  }
  msg.pending = false;
};

const friendlyArgs = (args: string): string => {
  const trimmed = args.trim();
  if (!trimmed) return '';
  try {
    const parsed = JSON.parse(trimmed);
    return JSON.stringify(parsed, null, 2);
  } catch {
    return trimmed;
  }
};

const optionalStreamString = (
  value: unknown,
  field: string,
): string | undefined => {
  if (value === undefined) {
    return undefined;
  }

  if (typeof value !== 'string') {
    throw new Error(`AI stream field "${field}" must be a string.`);
  }

  return value;
};

const requiredStreamString = (value: unknown, field: string): string => {
  const text = optionalStreamString(value, field);
  if (text === undefined || text.trim() === '') {
    throw new Error(`AI stream field "${field}" is required.`);
  }

  return text;
};

const requiredDeltaContent = (value: unknown): string => {
  const text = optionalStreamString(value, 'content');
  if (text === undefined) {
    throw new Error('AI stream field "content" is required.');
  }

  return text;
};

const appendToolChip = (
  kind: 'tool_call' | 'tool_result',
  tool: string,
  display: string | undefined,
  detail: string,
) => {
  messages.value.push({
    id: createMessageId(),
    kind,
    tool,
    display: display && display.trim() ? display : undefined,
    detail,
    expanded: false,
  });
  nextTick(() => scrollToBottom());
};

const toggleToolMessage = (message: ToolMessage) => {
  if (!message.detail.trim()) {
    return;
  }

  message.expanded = !message.expanded;
};

// 工具标签解析顺序：
//   1) Go 侧显式下发的 display（MCP 工具会带 "<server>/<tool>" 这种人类可读串）；
//   2) 没有 display 时尝试匹配 i18n 中的"工具.<sanitized name>" key（覆盖内置工具，如 calculator / knowledge_search）；
//   3) 仍无对应 i18n key 时返回 null，模板回落到 code 样式展示原始工具名。
const resolveToolLabel = (message: ToolMessage): string | null => {
  if (message.display && message.display.trim()) {
    return message.display;
  }
  const key = `工具.${message.tool}`;
  const translated = t(key);
  return translated && translated !== key ? translated : null;
};

const finalizeStream = (finalErrorMessage?: string) => {
  isStreaming.value = false;
  isStopping.value = false;
  currentTopic.value = null;
  closeStream();

  if (finalErrorMessage) {
    const existing = findAssistantById(currentAssistantId);
    if (existing) {
      existing.pending = false;
      existing.error = finalErrorMessage;
    } else {
      messages.value.push({
        id: createMessageId(),
        kind: 'text',
        role: 'assistant',
        content: '',
        pending: false,
        error: finalErrorMessage,
      });
    }
  } else {
    const existing = findAssistantById(currentAssistantId);
    if (existing) {
      existing.pending = false;
      if (!existing.content.trim()) {
        // 避免成功结束后留下空气泡。
        existing.error = t('AI助手暂无回复');
      }
    }
  }

  currentAssistantId = null;
  nextTick(() => {
    scrollToBottom();
    textareaRef.value?.focus();
  });
};

const finalizeStoppedStream = () => {
  isStreaming.value = false;
  isStopping.value = false;
  currentTopic.value = null;
  closeStream();

  const existing = findAssistantById(currentAssistantId);
  if (existing) {
    existing.pending = false;
    if (!existing.content.trim()) {
      existing.error = t('已停止生成');
    }
  } else {
    messages.value.push({
      id: createMessageId(),
      kind: 'text',
      role: 'assistant',
      content: '',
      pending: false,
      error: t('已停止生成'),
    });
  }

  currentAssistantId = null;
  nextTick(() => {
    scrollToBottom();
    textareaRef.value?.focus();
  });
};

// 从 earliest 回放，尽量补上先发布后订阅的竞态。
const subscribeToTopic = (topic: string) => {
  const params = new URLSearchParams();
  params.append('topic', topic);
  params.append('lastEventID', 'earliest');

  const source = new EventSource(`/.well-known/mercure?${params.toString()}`, {
    withCredentials: false,
  });
  currentEventSource = source;

  const handleStreamEventData = (data: string) => {
    const payload: StreamPayload = JSON.parse(data);

    switch (payload.type) {
      case 'delta': {
        const content = requiredDeltaContent(payload.content);
        let msg = findAssistantById(currentAssistantId);
        if (!msg) {
          openAssistantBubble();
          msg = findAssistantById(currentAssistantId);
        }
        if (msg) {
          msg.content += content;
          nextTick(() => scrollToBottom());
        }
        return;
      }

      case 'tool_call': {
        sealCurrentAssistantBubble();
        appendToolChip(
          'tool_call',
          requiredStreamString(payload.tool, 'tool'),
          optionalStreamString(payload.tool_display, 'tool_display') ??
            undefined,
          friendlyArgs(optionalStreamString(payload.args, 'args') ?? ''),
        );
        return;
      }

      case 'tool_result': {
        appendToolChip(
          'tool_result',
          requiredStreamString(payload.tool, 'tool'),
          optionalStreamString(payload.tool_display, 'tool_display') ??
            undefined,
          (optionalStreamString(payload.content, 'content') ?? '').trim(),
        );
        return;
      }

      case 'done': {
        finalizeStream();
        return;
      }

      case 'error': {
        finalizeStream(requiredStreamString(payload.error, 'error'));
        return;
      }

      default:
        finalizeStream(t('AI助手返回格式异常'));
        throw new Error(`Unsupported AI stream event type: ${payload.type}`);
    }
  };

  source.onmessage = (event) => {
    try {
      handleStreamEventData(event.data);
    } catch (error) {
      console.error('AI assistant stream payload is invalid.', error);
      finalizeStream(t('AI助手返回格式异常'));
      throw error;
    }
  };

  source.addEventListener('ai-chat', (event) => {
    try {
      handleStreamEventData((event as MessageEvent<string>).data);
    } catch (error) {
      console.error('AI assistant stream payload is invalid.', error);
      finalizeStream(t('AI助手返回格式异常'));
      throw error;
    }
  });

  source.onerror = () => {
    // 正常 close 也会触发 onerror，关闭状态时忽略。
    if (source.readyState === EventSource.CLOSED) {
      return;
    }
    finalizeStream(t('AI助手暂时不可用'));
  };
};

const handleSend = async () => {
  const value = inputValue.value.trim();
  if (!value || isStreaming.value || !hasSelectedModel.value) {
    return;
  }

  const modelLabelBeforeSend =
    modelOptions.value.find((option) => option.value === selectedModelId.value)
      ?.label ?? selectedModelId.value;

  // 先固定历史，避免把本次 user 消息重复带进 history。
  const historyPayload = buildHistoryPayload();

  messages.value.push({
    id: createMessageId(),
    kind: 'text',
    role: 'user',
    content: value,
  });
  inputValue.value = '';
  nextTick(() => {
    scrollToBottom();
    textareaRef.value?.focus();
  });

  openAssistantBubble();
  isStreaming.value = true;
  isStopping.value = false;
  currentTopic.value = null;

  closeStream();

  try {
    const response = await axios.post<{ topic: string }>(
      SendAiAssistantMessageAction.url(),
      {
        prompt: value,
        model_id: selectedModelId.value,
        history: historyPayload,
      },
      {
        headers: { Accept: 'application/json' },
      },
    );

    const topic = response.data?.topic;
    if (!topic) {
      finalizeStream(t('AI助手暂时不可用'));
      return;
    }

    currentTopic.value = topic;
    subscribeToTopic(topic);
  } catch (error: unknown) {
    let errorMessage = t('AI助手暂时不可用');
    if (axios.isAxiosError(error)) {
      const data = error.response?.data as
        | { message?: string; errors?: Record<string, string[]> }
        | undefined;
      if (data?.errors?.prompt?.[0]) {
        errorMessage = data.errors.prompt[0];
      } else if (data?.errors?.model_id?.[0]) {
        errorMessage = data.errors.model_id[0];
        invalidStoredModelLabel.value = modelLabelBeforeSend;
        selectedModelId.value = '';
      } else if (typeof data?.message === 'string' && data.message) {
        errorMessage = data.message;
      }
    }

    finalizeStream(errorMessage);
  }
};

const handleStop = async () => {
  if (!isStreaming.value || isStopping.value || !currentTopic.value) {
    return;
  }

  isStopping.value = true;

  try {
    await axios.post(
      StopAiAssistantMessageAction.url(),
      {
        topic: currentTopic.value,
      },
      {
        headers: { Accept: 'application/json' },
      },
    );

    finalizeStoppedStream();
  } catch {
    isStopping.value = false;
  }
};

const handleKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
    event.preventDefault();
    handleSend();
  }
};

const toggleOpen = () => {
  isOpen.value = !isOpen.value;
};

const closePanel = () => {
  isOpen.value = false;
};

watch(isOpen, (open) => {
  if (open) {
    nextTick(() => {
      textareaRef.value?.focus();
      scrollToBottom();
    });
  }
});

watch(
  [modelOptions, modelStorageKey],
  () => {
    syncSelectedModelFromStorage();
  },
  { immediate: true },
);

watch(selectedModelId, (value) => {
  if (value.trim() === '') {
    clearStoredModelSelection();
    return;
  }

  invalidStoredModelLabel.value = null;
  storeSelectedModel(value);
});

// --- Init position & global listeners ---
onMounted(() => {
  loadPosition();
  window.addEventListener('resize', onWindowResize);
  window.addEventListener('pointermove', onPointerMove);
  window.addEventListener('pointerup', onPointerUp);
  window.addEventListener('pointercancel', onPointerCancel);
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', onWindowResize);
  window.removeEventListener('pointermove', onPointerMove);
  window.removeEventListener('pointerup', onPointerUp);
  window.removeEventListener('pointercancel', onPointerCancel);
});

// 卸载时尽量通知后端停止当前流，避免服务端继续空跑。
const readXsrfTokenFromCookie = (): string | null => {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  if (!match) return null;
  return decodeURIComponent(match[1]);
};

const fireAndForgetStop = (topic: string) => {
  if (typeof fetch !== 'function') {
    return;
  }

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };
  const xsrf = readXsrfTokenFromCookie();
  if (xsrf) {
    headers['X-XSRF-TOKEN'] = xsrf;
  }

  void fetch(StopAiAssistantMessageAction.url(), {
    method: 'POST',
    credentials: 'same-origin',
    keepalive: true,
    headers,
    body: JSON.stringify({ topic }),
  }).catch((error: unknown) => {
    console.error('Failed to stop AI assistant stream.', error);
  });
};

onBeforeUnmount(() => {
  if (isStreaming.value && currentTopic.value) {
    fireAndForgetStop(currentTopic.value);
  }
  closeStream();
});
</script>

<template>
  <div
    ref="widgetRef"
    class="pointer-events-none fixed z-50"
    :style="containerStyle"
  >
    <div class="relative h-full w-full">
      <!-- Chat panel: absolutely positioned so it never shifts the button -->
      <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 opacity-0 scale-95"
        enter-to-class="translate-y-0 opacity-100 scale-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="translate-y-0 opacity-100 scale-100"
        leave-to-class="translate-y-2 opacity-0 scale-95"
      >
        <div
          v-if="isOpen"
          class="pointer-events-auto absolute right-full bottom-0 mr-2 flex h-[62vh] w-[min(28rem,calc(100vw-2rem))] origin-bottom-right flex-col overflow-hidden rounded-xl border border-border bg-background shadow-2xl"
        >
          <header
            class="flex shrink-0 items-center justify-between border-b border-border bg-gradient-to-r from-primary/10 via-primary/5 to-transparent px-4 py-3"
          >
            <div class="flex flex-col">
              <span class="text-sm leading-tight font-semibold">
                {{ t('AI助手') }}
              </span>
              <span class="text-xs leading-tight text-muted-foreground">
                {{ t('随时为你提供帮助') }}
              </span>
            </div>
            <Button
              variant="ghost"
              size="icon"
              class="h-8 w-8"
              :aria-label="t('关闭')"
              @click="closePanel"
            >
              <X class="h-4 w-4" />
            </Button>
          </header>

          <div
            ref="messagesRef"
            class="flex flex-1 flex-col gap-3 overflow-y-auto px-4 py-4"
          >
            <div
              v-if="!hasMessages"
              class="m-auto flex flex-col items-center gap-3 text-center text-muted-foreground"
            >
              <div
                class="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary"
              >
                <svg
                  viewBox="2.5 2.5 19 19"
                  fill="currentColor"
                  class="h-7 w-7"
                  aria-hidden="true"
                >
                  <path
                    fill-rule="evenodd"
                    clip-rule="evenodd"
                    d="M12 3a9 9 0 0 0-7.74 13.6l-1.21 3.62a.75.75 0 0 0 .95.95l3.62-1.21A9 9 0 1 0 12 3Zm-5.1 9a1.1 1.1 0 1 1 2.2 0 1.1 1.1 0 0 1-2.2 0Zm4 0a1.1 1.1 0 1 1 2.2 0 1.1 1.1 0 0 1-2.2 0Zm4 0a1.1 1.1 0 1 1 2.2 0 1.1 1.1 0 0 1-2.2 0Z"
                  />
                </svg>
              </div>
              <div class="space-y-1">
                <p class="text-sm font-medium text-foreground">
                  {{ t('你好，我是AI助手') }}
                </p>
                <p class="text-xs">
                  {{ t('告诉我你想做什么，我会尽力帮你') }}
                </p>
              </div>
            </div>

            <template v-else>
              <template v-for="message in messages" :key="message.id">
                <div
                  v-if="message.kind === 'text'"
                  :class="[
                    'flex',
                    message.role === 'user' ? 'justify-end' : 'justify-start',
                  ]"
                >
                  <div
                    :class="[
                      'max-w-[85%] rounded-2xl px-3 py-2 text-sm break-words shadow-sm',
                      message.role === 'user'
                        ? 'rounded-br-sm bg-primary whitespace-pre-wrap text-primary-foreground'
                        : 'rounded-bl-sm bg-muted text-foreground',
                      message.error ? 'border border-destructive/40' : '',
                    ]"
                  >
                    <template
                      v-if="message.role === 'assistant' && message.error"
                    >
                      <span class="text-destructive">
                        {{ message.error }}
                      </span>
                    </template>
                    <template v-else>
                      <div
                        v-if="message.role === 'assistant' && message.content"
                        class="ai-markdown space-y-2"
                        v-html="renderAssistantHtml(message)"
                      />
                      <span v-else class="whitespace-pre-wrap">{{
                        message.content
                      }}</span>
                      <span
                        v-if="
                          message.role === 'assistant' &&
                          message.pending &&
                          !message.content
                        "
                        class="inline-flex items-center gap-1 text-muted-foreground"
                      >
                        <Loader2 class="h-3.5 w-3.5 animate-spin" />
                        {{ t('AI助手正在思考…') }}
                      </span>
                      <span
                        v-else-if="message.pending"
                        class="-mb-[2px] ml-1 inline-block h-3 w-[2px] animate-pulse bg-current align-middle"
                        aria-hidden="true"
                      />
                    </template>
                  </div>
                </div>

                <div v-else class="flex justify-start">
                  <div
                    class="flex max-w-[90%] flex-col rounded-lg border border-border/60 bg-muted/40 text-xs text-foreground"
                  >
                    <button
                      type="button"
                      :class="[
                        'flex w-full items-start gap-2 px-2.5 py-2 text-left',
                        message.detail.trim()
                          ? 'cursor-pointer hover:bg-background/40'
                          : 'cursor-default',
                      ]"
                      :disabled="!message.detail.trim()"
                      :aria-expanded="message.expanded"
                      :aria-label="
                        message.expanded ? t('收起详情') : t('查看详情')
                      "
                      @click="toggleToolMessage(message)"
                    >
                      <component
                        :is="
                          message.kind === 'tool_call' ? Wrench : CheckCircle2
                        "
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                      />
                      <div class="flex min-w-0 flex-1 items-center gap-1.5">
                        <span class="shrink-0 font-medium">
                          {{
                            message.kind === 'tool_call'
                              ? t('调用工具')
                              : t('工具结果')
                          }}
                        </span>
                        <span
                          v-if="resolveToolLabel(message)"
                          class="min-w-0 truncate text-foreground"
                          :title="message.tool"
                        >
                          {{ resolveToolLabel(message) }}
                        </span>
                        <code
                          v-else
                          class="min-w-0 truncate rounded bg-background/60 px-1 py-0.5 font-mono text-[11px]"
                        >
                          {{ message.tool }}
                        </code>
                      </div>
                      <ChevronRight
                        v-if="message.detail.trim()"
                        :class="[
                          'mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform',
                          message.expanded ? 'rotate-90' : '',
                        ]"
                        aria-hidden="true"
                      />
                    </button>
                    <pre
                      v-if="message.detail.trim() && message.expanded"
                      class="max-h-40 overflow-auto border-t border-current/10 px-2.5 py-2 font-mono text-[11px] leading-snug break-words whitespace-pre-wrap text-muted-foreground"
                      >{{ message.detail }}</pre
                    >
                  </div>
                </div>
              </template>
            </template>
          </div>

          <div class="shrink-0 border-t border-border bg-card/50 p-3">
            <div
              class="rounded-xl border border-input bg-background px-3 py-2 shadow-xs transition-[box-shadow,border-color] duration-200 focus-within:border-foreground/30 focus-within:shadow-[0_8px_28px_-6px_color-mix(in_oklab,var(--foreground)_30%,transparent)]"
            >
              <textarea
                ref="textareaRef"
                v-model="inputValue"
                rows="2"
                class="max-h-36 min-h-12 w-full resize-none bg-transparent text-sm leading-5 outline-none placeholder:text-muted-foreground"
                @keydown="handleKeydown"
              />
              <div class="mt-2 flex items-end gap-2">
                <div class="flex-1 space-y-2">
                  <Select
                    v-model="selectedModelId"
                    :disabled="isStreaming || !hasAvailableModels"
                  >
                    <SelectTrigger class="h-9 w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectGroup
                        v-for="group in groupedModelOptions"
                        :key="group.providerName"
                      >
                        <SelectLabel>{{ group.providerName }}</SelectLabel>
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

                <Button
                  size="icon"
                  :variant="isStreaming ? 'outline' : 'default'"
                  :class="[
                    'h-9 w-9 shrink-0 rounded-lg',
                    isStreaming
                      ? 'border-primary/25 bg-primary/10 text-primary shadow-none hover:bg-primary/15 hover:text-primary'
                      : '',
                  ]"
                  :disabled="
                    isStreaming
                      ? isStopping || !currentTopic
                      : !inputValue.trim() || !hasSelectedModel
                  "
                  :aria-label="isStreaming ? t('停止生成') : t('发送')"
                  @click="isStreaming ? handleStop() : handleSend()"
                >
                  <Loader2
                    v-if="isStreaming && isStopping"
                    class="h-3.5 w-3.5 animate-spin"
                  />
                  <Square
                    v-else-if="isStreaming"
                    class="h-3.5 w-3.5 fill-current"
                  />
                  <Send v-else class="h-3.5 w-3.5" />
                </Button>
              </div>
            </div>
            <div class="mt-2 space-y-1">
              <p
                v-if="invalidStoredModelLabel"
                class="text-[11px] text-amber-600"
              >
                {{
                  t('你上次选择的模型已不可用，请重新选择。') +
                  ` (${invalidStoredModelLabel})`
                }}
              </p>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Icon-only entry: no filled circle background -->
      <button
        type="button"
        data-drag-handle
        :class="[
          'group pointer-events-auto relative flex h-10 w-10 items-center justify-center rounded-full text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
        ]"
        :aria-label="isOpen ? t('关闭AI助手') : t('打开AI助手')"
        :aria-expanded="isOpen"
        @click="onButtonClick"
        @pointerdown="onPointerDown"
      >
        <svg
          viewBox="2.5 2.5 19 19"
          fill="currentColor"
          class="h-9 w-9"
          aria-hidden="true"
        >
          <path
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M12 3a9 9 0 0 0-7.74 13.6l-1.21 3.62a.75.75 0 0 0 .95.95l3.62-1.21A9 9 0 1 0 12 3Zm-5.1 9a1.1 1.1 0 1 1 2.2 0 1.1 1.1 0 0 1-2.2 0Zm4 0a1.1 1.1 0 1 1 2.2 0 1.1 1.1 0 0 1-2.2 0Zm4 0a1.1 1.1 0 1 1 2.2 0 1.1 1.1 0 0 1-2.2 0Z"
          />
        </svg>
      </button>
    </div>
  </div>
</template>

<style scoped>
/* v-html 内容统一在这里补齐基础排版。 */
.ai-markdown :deep(p) {
  line-height: 1.65;
}
.ai-markdown :deep(p + p) {
  margin-top: 0.5rem;
}
.ai-markdown :deep(h1),
.ai-markdown :deep(h2),
.ai-markdown :deep(h3),
.ai-markdown :deep(h4),
.ai-markdown :deep(h5),
.ai-markdown :deep(h6) {
  font-weight: 600;
  line-height: 1.3;
}
.ai-markdown :deep(h1),
.ai-markdown :deep(h2) {
  font-size: 0.875rem;
}
.ai-markdown :deep(h3),
.ai-markdown :deep(h4),
.ai-markdown :deep(h5),
.ai-markdown :deep(h6) {
  font-size: 0.8125rem;
}
.ai-markdown :deep(strong) {
  font-weight: 600;
}
.ai-markdown :deep(em) {
  font-style: italic;
}
.ai-markdown :deep(a) {
  font-weight: 500;
  text-decoration: underline;
  text-underline-offset: 2px;
}
.ai-markdown :deep(code) {
  border-radius: 0.25rem;
  padding: 0.125rem 0.25rem;
  background-color: oklch(from var(--background) l c h / 70%);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
}
.ai-markdown :deep(pre) {
  max-width: 100%;
  overflow: auto;
  border-radius: 0.375rem;
  border: 1px solid var(--border);
  background-color: oklch(from var(--background) l c h / 70%);
  padding: 0.5rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
  line-height: 1.4;
  white-space: pre;
  color: var(--foreground);
}
.ai-markdown :deep(pre) code {
  padding: 0;
  background: transparent;
  font-size: inherit;
}
.ai-markdown :deep(blockquote) {
  border-left: 2px solid var(--border);
  padding-left: 0.5rem;
  color: var(--muted-foreground);
}
.ai-markdown :deep(ul),
.ai-markdown :deep(ol) {
  padding-left: 1rem;
  line-height: 1.65;
}
.ai-markdown :deep(ul) {
  list-style: disc;
}
.ai-markdown :deep(ol) {
  list-style: decimal;
}
.ai-markdown :deep(li + li) {
  margin-top: 0.25rem;
}
.ai-markdown :deep(table) {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
  border: 1px solid var(--border);
  border-radius: 0.375rem;
  overflow: hidden;
}
.ai-markdown :deep(th) {
  font-weight: 600;
  text-align: left;
  border-bottom: 1px solid var(--border);
  padding: 0.25rem 0.5rem;
  background-color: oklch(from var(--background) l c h / 60%);
}
.ai-markdown :deep(td) {
  padding: 0.25rem 0.5rem;
  border-top: 1px solid oklch(from var(--border) l c h / 60%);
  vertical-align: top;
}
.ai-markdown :deep(hr) {
  border: 0;
  border-top: 1px solid var(--border);
  margin: 0.5rem 0;
}
</style>
