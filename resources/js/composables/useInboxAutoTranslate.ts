/**
 * 收件箱自动翻译可见消息的组合式函数。
 *
 * 通过 IntersectionObserver 追踪时间线中可见的消息，自动对尚未翻译的消息
 * 发起批量翻译请求，并在消息获得翻译后停止 pending 状态。
 */
import { localeMatches } from '@/lib/locale';
import inboxActions from '@/routes/admin/inbox';
import type {
  ContactStitchedTimelineData,
  ContactTimelineEntryData,
  InboxSelectionData,
} from '@/types/generated';
import axios from 'axios';
import { type ComputedRef, type Ref, onUnmounted, ref, watch } from 'vue';

/** 请求去抖延迟（毫秒） */
const AUTO_TRANSLATE_DEBOUNCE_MS = 500;
/** 单条消息翻译失败后的冷却时间（毫秒） */
const AUTO_TRANSLATE_RETRY_COOLDOWN_MS = 60_000;
/** 单条消息 pending 超时（毫秒） */
const AUTO_TRANSLATE_PENDING_TIMEOUT_MS = 30_000;
/** 单次批量翻译上限 */
const AUTO_TRANSLATE_BATCH_SIZE = 20;

export interface UseInboxAutoTranslateOptions {
  /** 当前选中的会话/联系人数据 */
  selection: ComputedRef<InboxSelectionData | null>;
  /** 当前登录用户 ID */
  currentUserId: ComputedRef<string | null>;
  /** 当前登录用户语言 */
  currentUserLocale: ComputedRef<string>;
  /** 拼接后的完整时间线 */
  activeStitchedTimeline: ComputedRef<ContactStitchedTimelineData | null>;
  /** 时间线滚动容器 */
  timelineScrollRef: Ref<HTMLElement | null>;
  /** 是否启用自动翻译 */
  enabled: Ref<boolean>;
}

export interface UseInboxAutoTranslateReturn {
  /** 正在翻译中的消息 ID 集合（用于 UI loading 指示） */
  autoTranslatingMessageIds: Ref<Set<string>>;
  /** 清理全部资源（observer、定时器、pending 状态） */
  cleanup: () => void;
  /** 刷新 observer 并调度翻译（外部在 enabled 切换为 true 时调用） */
  scheduleObserverRefresh: () => void;
  /** 停止 observer 和定时器（外部在 enabled 切换为 false 时调用） */
  stopObserverAndTimers: () => void;
}

export function useInboxAutoTranslate(
  options: UseInboxAutoTranslateOptions,
): UseInboxAutoTranslateReturn {
  const {
    selection,
    currentUserId,
    currentUserLocale,
    activeStitchedTimeline,
    timelineScrollRef,
    enabled,
  } = options;

  // --- 内部状态 ---

  const visibleTimelineMessageIds = ref<Set<string>>(new Set());
  const autoTranslatingMessageIds = ref<Set<string>>(new Set());
  const autoTranslateQueuedAt = new Map<string, number>();
  const autoTranslatePendingTimers = new Map<string, number>();
  let autoTranslateObserver: IntersectionObserver | null = null;
  let autoTranslateObserveTimer: number | null = null;
  let autoTranslateRequestTimer: number | null = null;
  let autoTranslateRequestController: AbortController | null = null;

  // --- 内部函数 ---

  function clearAutoTranslateObserver(): void {
    autoTranslateObserver?.disconnect();
    autoTranslateObserver = null;
    visibleTimelineMessageIds.value = new Set();
  }

  function clearAutoTranslateTimers(): void {
    if (autoTranslateObserveTimer !== null) {
      window.clearTimeout(autoTranslateObserveTimer);
      autoTranslateObserveTimer = null;
    }
    if (autoTranslateRequestTimer !== null) {
      window.clearTimeout(autoTranslateRequestTimer);
      autoTranslateRequestTimer = null;
    }
    autoTranslateRequestController?.abort();
    autoTranslateRequestController = null;
  }

  function stopAutoTranslatePending(messageId: string): void {
    const timer = autoTranslatePendingTimers.get(messageId);
    if (timer !== undefined) {
      window.clearTimeout(timer);
      autoTranslatePendingTimers.delete(messageId);
    }

    if (autoTranslatingMessageIds.value.has(messageId)) {
      const next = new Set(autoTranslatingMessageIds.value);
      next.delete(messageId);
      autoTranslatingMessageIds.value = next;
    }
  }

  function clearAutoTranslatePending(): void {
    autoTranslatePendingTimers.forEach((timer) => window.clearTimeout(timer));
    autoTranslatePendingTimers.clear();
    autoTranslatingMessageIds.value = new Set();
  }

  function markAutoTranslatePending(messageIds: string[]): void {
    const next = new Set(autoTranslatingMessageIds.value);

    messageIds.forEach((messageId) => {
      next.add(messageId);

      const existingTimer = autoTranslatePendingTimers.get(messageId);
      if (existingTimer !== undefined) {
        window.clearTimeout(existingTimer);
      }

      autoTranslatePendingTimers.set(
        messageId,
        window.setTimeout(() => {
          stopAutoTranslatePending(messageId);
        }, AUTO_TRANSLATE_PENDING_TIMEOUT_MS),
      );
    });

    autoTranslatingMessageIds.value = next;
  }

  function messageHasViewerTranslation(
    entry: ContactTimelineEntryData,
    localeValue: string,
  ): boolean {
    const payload = entry.payload as
      | {
          translations?: Record<string, { text?: unknown }>;
        }
      | null
      | undefined;
    const text = payload?.translations?.[localeValue]?.text;

    return typeof text === 'string' && text.trim().length > 0;
  }

  function messageCanAutoTranslate(entry: ContactTimelineEntryData): boolean {
    const localeValue = currentUserLocale.value;
    if (
      !selection.value?.can_translate_messages ||
      entry.conversation_id !== selection.value.conversation.id ||
      entry.type !== 'message' ||
      entry.kind !== 'text' ||
      !['visitor', 'ai', 'teammate'].includes(String(entry.role)) ||
      typeof entry.content !== 'string' ||
      entry.content.trim() === '' ||
      entry.recalled_at ||
      messageHasViewerTranslation(entry, localeValue)
    ) {
      return false;
    }

    if (
      entry.role === 'teammate' &&
      currentUserId.value !== null &&
      entry.actor_user_id === currentUserId.value
    ) {
      return false;
    }

    if (
      typeof entry.content_locale === 'string' &&
      localeMatches(entry.content_locale, localeValue)
    ) {
      return false;
    }

    return true;
  }

  function messageNeedsAutoTranslation(
    entry: ContactTimelineEntryData,
  ): boolean {
    if (!messageCanAutoTranslate(entry)) {
      return false;
    }

    const lastQueuedAt = autoTranslateQueuedAt.get(entry.id) ?? 0;
    return Date.now() - lastQueuedAt >= AUTO_TRANSLATE_RETRY_COOLDOWN_MS;
  }

  function syncAutoTranslatePendingWithTimeline(): void {
    const timeline = activeStitchedTimeline.value;
    if (!timeline || autoTranslatingMessageIds.value.size === 0) {
      return;
    }

    const entriesById = new Map(
      timeline.entries.map((entry) => [entry.id, entry]),
    );
    autoTranslatingMessageIds.value.forEach((messageId) => {
      const entry = entriesById.get(messageId);
      if (!entry || !messageCanAutoTranslate(entry)) {
        stopAutoTranslatePending(messageId);
      }
    });
  }

  function visibleMessageIdsNeedingTranslation(): string[] {
    const timeline = activeStitchedTimeline.value;
    if (
      !enabled.value ||
      !selection.value?.can_translate_messages ||
      !timeline
    ) {
      return [];
    }

    const visibleIds = visibleTimelineMessageIds.value;

    return timeline.entries
      .filter(
        (entry) =>
          visibleIds.has(entry.id) && messageNeedsAutoTranslation(entry),
      )
      .map((entry) => entry.id)
      .slice(0, AUTO_TRANSLATE_BATCH_SIZE);
  }

  function scheduleAutoTranslateVisibleMessages(): void {
    if (autoTranslateRequestTimer !== null) {
      window.clearTimeout(autoTranslateRequestTimer);
      autoTranslateRequestTimer = null;
    }

    if (!enabled.value) {
      return;
    }

    autoTranslateRequestTimer = window.setTimeout(() => {
      autoTranslateRequestTimer = null;
      void queueVisibleMessageTranslations();
    }, AUTO_TRANSLATE_DEBOUNCE_MS);
  }

  async function queueVisibleMessageTranslations(): Promise<void> {
    const conversation = selection.value?.conversation;
    const messageIds = visibleMessageIdsNeedingTranslation();
    if (!conversation || messageIds.length === 0) {
      return;
    }

    const queuedAt = Date.now();
    messageIds.forEach((messageId) =>
      autoTranslateQueuedAt.set(messageId, queuedAt),
    );
    markAutoTranslatePending(messageIds);
    autoTranslateRequestController?.abort();
    const controller = new AbortController();
    autoTranslateRequestController = controller;

    try {
      await axios.post(
        inboxActions.conversations.messages.queueTranslations.url({
          conversation: conversation.id,
        }),
        { message_ids: messageIds },
        { signal: controller.signal },
      );
    } catch {
      if (!controller.signal.aborted) {
        messageIds.forEach((messageId) =>
          autoTranslateQueuedAt.delete(messageId),
        );
        messageIds.forEach((messageId) => stopAutoTranslatePending(messageId));
      }
    } finally {
      if (autoTranslateRequestController === controller) {
        autoTranslateRequestController = null;
      }
    }
  }

  function refreshAutoTranslateObserver(): void {
    clearAutoTranslateObserver();

    if (
      typeof window === 'undefined' ||
      !enabled.value ||
      !selection.value?.can_translate_messages ||
      !timelineScrollRef.value
    ) {
      return;
    }

    autoTranslateObserver = new IntersectionObserver(
      (entries) => {
        const next = new Set(visibleTimelineMessageIds.value);
        entries.forEach((entry) => {
          const messageId = entry.target.getAttribute(
            'data-inbox-timeline-message-id',
          );
          if (!messageId) {
            return;
          }

          if (entry.isIntersecting) {
            next.add(messageId);
          } else {
            next.delete(messageId);
          }
        });
        visibleTimelineMessageIds.value = next;
        scheduleAutoTranslateVisibleMessages();
      },
      {
        root: timelineScrollRef.value,
        rootMargin: '160px 0px',
        threshold: 0.1,
      },
    );

    timelineScrollRef.value
      .querySelectorAll<HTMLElement>('[data-inbox-timeline-message-id]')
      .forEach((element) => autoTranslateObserver?.observe(element));
  }

  function scheduleAutoTranslateObserverRefresh(): void {
    if (autoTranslateObserveTimer !== null) {
      window.clearTimeout(autoTranslateObserveTimer);
    }

    autoTranslateObserveTimer = window.setTimeout(() => {
      autoTranslateObserveTimer = null;
      refreshAutoTranslateObserver();
    }, 0);
  }

  // --- 监听器 ---

  // 会话切换、翻译权限变化、用户语言变化、时间线条目变化时重新同步
  watch(
    () => [
      selection.value?.conversation.id,
      selection.value?.can_translate_messages,
      currentUserLocale.value,
      activeStitchedTimeline.value?.entries
        .map((entry) =>
          [
            entry.id,
            entry.content_locale ?? '',
            messageHasViewerTranslation(entry, currentUserLocale.value)
              ? '1'
              : '0',
          ].join(':'),
        )
        .join('|'),
    ],
    () => {
      syncAutoTranslatePendingWithTimeline();
      scheduleAutoTranslateObserverRefresh();
    },
    { immediate: true, flush: 'post' },
  );

  // --- 清理 ---

  function cleanup(): void {
    clearAutoTranslateObserver();
    clearAutoTranslateTimers();
    clearAutoTranslatePending();
  }

  onUnmounted(cleanup);

  return {
    autoTranslatingMessageIds,
    cleanup,
    scheduleObserverRefresh: scheduleAutoTranslateObserverRefresh,
    stopObserverAndTimers: () => {
      clearAutoTranslateObserver();
      clearAutoTranslateTimers();
    },
  };
}
