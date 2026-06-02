/**
 * 文件说明：前端组合式逻辑，在后台布局中订阅接待实时事件并触发浏览器通知和声音提醒。
 */
import { openMercureEventSource, receptionInboxTopic } from '@/lib/mercure';
import admin from '@/routes/admin';
import type { UserNotificationPreferencesData } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, watch, type Ref } from 'vue';
import { showBrowserNotification } from './useBrowserNotification';
import { useI18n } from './useI18n';
import { playNotificationSound } from './useNotificationSound';

interface SystemNotificationAlertOptions {
  userId: Ref<string>;
  preferences: Ref<UserNotificationPreferencesData>;
}

interface ReceptionRealtimePayload {
  event?: string;
  conversation_id?: string;
  occurred_at?: string;
  assigned_user_id?: string | null;
  previous_assigned_user_id?: string | null;
  inbox_status?: string;
  message_id?: string | null;
  last_message_preview?: string | null;
  contact_name?: string | null;
  channel_name?: string | null;
}

interface NotificationAlert {
  title: string;
  body: string;
  conversationId: string;
}

function alertFingerprint(payload: ReceptionRealtimePayload): string {
  if (payload.message_id) {
    return payload.message_id;
  }

  if (payload.occurred_at) {
    return payload.occurred_at;
  }

  return `${payload.event}:${payload.conversation_id}`;
}

export function useSystemNotificationAlerts(
  options: SystemNotificationAlertOptions,
): void {
  const { t } = useI18n();
  let source: EventSource | null = null;
  let lastAlertKey: string | null = null;

  function closeSource(): void {
    if (source) {
      source.close();
      source = null;
    }
  }

  function conversationUrl(conversationId: string): string {
    return admin.inbox.show.url({
      query: { conversation_id: conversationId },
    });
  }

  function buildBody(payload: ReceptionRealtimePayload): string {
    const parts = [payload.contact_name, payload.last_message_preview]
      .map((value) => (typeof value === 'string' ? value.trim() : ''))
      .filter((value) => value !== '');

    return parts.length > 0 ? parts.join('：') : t('点击查看会话');
  }

  function resolveAlert(
    payload: ReceptionRealtimePayload,
  ): NotificationAlert | null {
    const preferences = options.preferences.value;
    const conversationId = payload.conversation_id;
    if (!conversationId) {
      return null;
    }

    if (
      payload.event === 'visitor_message_created' &&
      payload.assigned_user_id === options.userId.value &&
      preferences.notify_assigned_conversations
    ) {
      return {
        title: t('新的访客消息'),
        body: buildBody(payload),
        conversationId,
      };
    }

    if (
      payload.event === 'visitor_message_created' &&
      payload.assigned_user_id === null &&
      payload.inbox_status === 'teammate_pending' &&
      preferences.notify_unassigned_conversations
    ) {
      return {
        title: t('新的待接入会话'),
        body: buildBody(payload),
        conversationId,
      };
    }

    if (
      payload.event === 'conversation_transferred' &&
      payload.assigned_user_id === options.userId.value &&
      payload.previous_assigned_user_id !== options.userId.value &&
      preferences.notify_assigned_conversations
    ) {
      return {
        title: t('会话已转接给你'),
        body: buildBody(payload),
        conversationId,
      };
    }

    if (
      payload.event === 'handoff_requested' &&
      payload.inbox_status === 'teammate_pending' &&
      preferences.notify_unassigned_conversations
    ) {
      return {
        title: t('新的待接入会话'),
        body: buildBody(payload),
        conversationId,
      };
    }

    return null;
  }

  function openConversation(conversationId: string): void {
    if (typeof window !== 'undefined') {
      window.focus();
    }

    router.visit(conversationUrl(conversationId), {
      preserveState: false,
      preserveScroll: false,
    });
  }

  function handlePayload(payload: ReceptionRealtimePayload): void {
    const preferences = options.preferences.value;
    const alert = resolveAlert(payload);
    if (!alert) {
      return;
    }

    const alertKey = `${payload.event}:${alert.conversationId}:${alertFingerprint(payload)}`;
    if (alertKey === lastAlertKey) {
      return;
    }
    lastAlertKey = alertKey;

    if (preferences.sound_enabled) {
      playNotificationSound(preferences.sound);
    }

    if (preferences.browser_notifications_enabled) {
      showBrowserNotification({
        title: alert.title,
        body: alert.body,
        url: conversationUrl(alert.conversationId),
        onClick: () => openConversation(alert.conversationId),
      });
    }
  }

  function subscribe(): void {
    closeSource();

    const preferences = options.preferences.value;
    if (
      !preferences.browser_notifications_enabled &&
      !preferences.sound_enabled
    ) {
      return;
    }

    source = openMercureEventSource(receptionInboxTopic());

    source.addEventListener('reception', (event) => {
      handlePayload(JSON.parse((event as MessageEvent).data));
    });
  }

  watch(
    [options.userId, options.preferences],
    subscribe,
    { immediate: true },
  );

  onBeforeUnmount(closeSource);
}
