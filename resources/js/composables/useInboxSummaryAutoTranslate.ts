/**
 * 收件箱自动翻译会话摘要的组合式函数。
 *
 * 通过 IntersectionObserver 追踪当前会话摘要和历史会话摘要块，按客服当前语言补翻摘要文本。
 */
import { localeMatches } from '@/lib/locale';
import inboxActions from '@/routes/admin/inbox';
import type {
  ContactStitchedTimelineData,
  ConversationSummaryData,
  InboxSelectionData,
} from '@/types/generated';
import axios from 'axios';
import { type ComputedRef, type Ref, onUnmounted, ref, watch } from 'vue';

const AUTO_TRANSLATE_DEBOUNCE_MS = 500;
const AUTO_TRANSLATE_RETRY_COOLDOWN_MS = 60_000;
const AUTO_TRANSLATE_PENDING_TIMEOUT_MS = 30_000;
const AUTO_TRANSLATE_BATCH_SIZE = 20;

export interface UseInboxSummaryAutoTranslateOptions {
  selection: ComputedRef<InboxSelectionData | null>;
  currentUserLocale: ComputedRef<string>;
  activeStitchedTimeline: ComputedRef<ContactStitchedTimelineData | null>;
  timelineScrollRef: Ref<HTMLElement | null>;
  enabled: Ref<boolean>;
}

export interface UseInboxSummaryAutoTranslateReturn {
  autoTranslatingSummaryIds: Ref<Set<string>>;
  cleanup: () => void;
  scheduleObserverRefresh: () => void;
  stopObserverAndTimers: () => void;
}

export function useInboxSummaryAutoTranslate(
  options: UseInboxSummaryAutoTranslateOptions,
): UseInboxSummaryAutoTranslateReturn {
  const {
    selection,
    currentUserLocale,
    activeStitchedTimeline,
    timelineScrollRef,
    enabled,
  } = options;

  const visibleSummaryIds = ref<Set<string>>(new Set());
  const autoTranslatingSummaryIds = ref<Set<string>>(new Set());
  const queuedAt = new Map<string, number>();
  const pendingTimers = new Map<string, number>();
  let observer: IntersectionObserver | null = null;
  let observeTimer: number | null = null;
  let requestTimer: number | null = null;
  let requestController: AbortController | null = null;

  function clearObserver(): void {
    observer?.disconnect();
    observer = null;
    visibleSummaryIds.value = new Set();
  }

  function clearTimers(): void {
    if (observeTimer !== null) {
      window.clearTimeout(observeTimer);
      observeTimer = null;
    }
    if (requestTimer !== null) {
      window.clearTimeout(requestTimer);
      requestTimer = null;
    }
    requestController?.abort();
    requestController = null;
  }

  function stopPending(conversationId: string): void {
    const timer = pendingTimers.get(conversationId);
    if (timer !== undefined) {
      window.clearTimeout(timer);
      pendingTimers.delete(conversationId);
    }

    if (autoTranslatingSummaryIds.value.has(conversationId)) {
      const next = new Set(autoTranslatingSummaryIds.value);
      next.delete(conversationId);
      autoTranslatingSummaryIds.value = next;
    }
  }

  function clearPending(): void {
    pendingTimers.forEach((timer) => window.clearTimeout(timer));
    pendingTimers.clear();
    autoTranslatingSummaryIds.value = new Set();
  }

  function markPending(conversationIds: string[]): void {
    const next = new Set(autoTranslatingSummaryIds.value);

    conversationIds.forEach((conversationId) => {
      next.add(conversationId);

      const timer = pendingTimers.get(conversationId);
      if (timer !== undefined) {
        window.clearTimeout(timer);
      }

      pendingTimers.set(
        conversationId,
        window.setTimeout(() => {
          stopPending(conversationId);
        }, AUTO_TRANSLATE_PENDING_TIMEOUT_MS),
      );
    });

    autoTranslatingSummaryIds.value = next;
  }

  function hasViewerTranslation(
    conversation: ConversationSummaryData,
  ): boolean {
    const text =
      conversation.summary_translations?.[currentUserLocale.value]?.text;

    return typeof text === 'string' && text.trim().length > 0;
  }

  function summaryCanAutoTranslate(
    conversation: ConversationSummaryData,
  ): boolean {
    const localeValue = currentUserLocale.value;

    if (
      !selection.value?.can_translate_messages ||
      !conversation.summary ||
      conversation.summary.trim() === '' ||
      hasViewerTranslation(conversation)
    ) {
      return false;
    }

    if (
      conversation.summary_locale &&
      localeMatches(conversation.summary_locale, localeValue)
    ) {
      return false;
    }

    return true;
  }

  function summaryNeedsAutoTranslation(
    conversation: ConversationSummaryData,
  ): boolean {
    if (!summaryCanAutoTranslate(conversation)) {
      return false;
    }

    const lastQueuedAt = queuedAt.get(conversation.id) ?? 0;
    return Date.now() - lastQueuedAt >= AUTO_TRANSLATE_RETRY_COOLDOWN_MS;
  }

  function allConversations(): ConversationSummaryData[] {
    const map = new Map<string, ConversationSummaryData>();
    const selected = selection.value?.conversation;
    if (selected) {
      map.set(selected.id, selected);
    }
    activeStitchedTimeline.value?.conversations.forEach((conversation) => {
      map.set(conversation.id, conversation);
    });

    return Array.from(map.values());
  }

  function syncPendingWithConversations(): void {
    if (autoTranslatingSummaryIds.value.size === 0) {
      return;
    }

    const conversations = new Map(
      allConversations().map((conversation) => [conversation.id, conversation]),
    );
    autoTranslatingSummaryIds.value.forEach((conversationId) => {
      const conversation = conversations.get(conversationId);
      if (!conversation || !summaryCanAutoTranslate(conversation)) {
        stopPending(conversationId);
      }
    });
  }

  function visibleIdsNeedingTranslation(): string[] {
    if (
      !enabled.value ||
      !selection.value?.can_translate_messages ||
      !activeStitchedTimeline.value
    ) {
      return [];
    }

    const visibleIds = visibleSummaryIds.value;

    return allConversations()
      .filter(
        (conversation) =>
          visibleIds.has(conversation.id) &&
          summaryNeedsAutoTranslation(conversation),
      )
      .map((conversation) => conversation.id)
      .slice(0, AUTO_TRANSLATE_BATCH_SIZE);
  }

  function scheduleVisibleSummaryTranslations(): void {
    if (requestTimer !== null) {
      window.clearTimeout(requestTimer);
      requestTimer = null;
    }

    if (!enabled.value) {
      return;
    }

    requestTimer = window.setTimeout(() => {
      requestTimer = null;
      void queueVisibleSummaryTranslations();
    }, AUTO_TRANSLATE_DEBOUNCE_MS);
  }

  async function queueVisibleSummaryTranslations(): Promise<void> {
    const conversation = selection.value?.conversation;
    const conversationIds = visibleIdsNeedingTranslation();
    if (!conversation || conversationIds.length === 0) {
      return;
    }

    const now = Date.now();
    conversationIds.forEach((conversationId) =>
      queuedAt.set(conversationId, now),
    );
    markPending(conversationIds);
    requestController?.abort();
    const controller = new AbortController();
    requestController = controller;

    try {
      await axios.post(
        inboxActions.conversations.summaries.queueTranslations.url({
          conversation: conversation.id,
        }),
        { conversation_ids: conversationIds },
        { signal: controller.signal },
      );
    } catch {
      if (!controller.signal.aborted) {
        conversationIds.forEach((conversationId) =>
          queuedAt.delete(conversationId),
        );
        conversationIds.forEach((conversationId) =>
          stopPending(conversationId),
        );
      }
    } finally {
      if (requestController === controller) {
        requestController = null;
      }
    }
  }

  function refreshObserver(): void {
    clearObserver();

    if (
      typeof window === 'undefined' ||
      !enabled.value ||
      !selection.value?.can_translate_messages ||
      !timelineScrollRef.value
    ) {
      return;
    }

    observer = new IntersectionObserver(
      (entries) => {
        const next = new Set(visibleSummaryIds.value);
        entries.forEach((entry) => {
          const conversationId = entry.target.getAttribute(
            'data-inbox-conversation-summary-id',
          );
          if (!conversationId) {
            return;
          }

          if (entry.isIntersecting) {
            next.add(conversationId);
          } else {
            next.delete(conversationId);
          }
        });
        visibleSummaryIds.value = next;
        scheduleVisibleSummaryTranslations();
      },
      {
        root: timelineScrollRef.value,
        rootMargin: '160px 0px',
        threshold: 0.1,
      },
    );

    timelineScrollRef.value
      .querySelectorAll<HTMLElement>('[data-inbox-conversation-summary-id]')
      .forEach((element) => observer?.observe(element));
  }

  function scheduleObserverRefresh(): void {
    if (observeTimer !== null) {
      window.clearTimeout(observeTimer);
    }

    observeTimer = window.setTimeout(() => {
      observeTimer = null;
      refreshObserver();
    }, 0);
  }

  watch(
    () => [
      selection.value?.conversation.id,
      selection.value?.can_translate_messages,
      currentUserLocale.value,
      allConversations()
        .map((conversation) =>
          [
            conversation.id,
            conversation.summary ?? '',
            conversation.summary_locale ?? '',
            hasViewerTranslation(conversation) ? '1' : '0',
          ].join(':'),
        )
        .join('|'),
    ],
    () => {
      syncPendingWithConversations();
      scheduleObserverRefresh();
    },
    { immediate: true, flush: 'post' },
  );

  function cleanup(): void {
    clearObserver();
    clearTimers();
    clearPending();
  }

  onUnmounted(cleanup);

  return {
    autoTranslatingSummaryIds,
    cleanup,
    scheduleObserverRefresh,
    stopObserverAndTimers: () => {
      clearObserver();
      clearTimers();
      clearPending();
    },
  };
}
