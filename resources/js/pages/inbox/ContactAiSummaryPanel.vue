<!--
  文件说明：收件箱右侧联系人 AI 摘要面板，展示联系人级固定字段摘要并按当前客服语言自动补翻。
-->
<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { localeMatches } from '@/lib/locale';
import inboxActions from '@/routes/admin/inbox';
import type {
  ContactAiSummaryData,
  InboxContactProfileData,
  MessageTranslationData,
} from '@/types/generated';
import axios from 'axios';
import { LoaderCircle } from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
  contactProfile: InboxContactProfileData | null;
  currentUserLocale: string;
  canTranslate: boolean;
  autoTranslateEnabled: boolean;
}>();

const { t } = useI18n();

type ContactSummaryTranslation = {
  profile_summary?: MessageTranslationData | null;
  open_issues?: MessageTranslationData[];
  preferences?: MessageTranslationData[];
  recent_topics?: MessageTranslationData[];
};

const TRANSLATION_PENDING_TIMEOUT_MS = 30_000;

const pending = ref(false);
const showOriginal = ref(false);
let pendingTimer: number | null = null;

const summary = computed<ContactAiSummaryData | null>(
  () => props.contactProfile?.ai_summary ?? null,
);
const translation = computed<ContactSummaryTranslation | null>(() => {
  const translations = summary.value?.translations as
    | Record<string, unknown>
    | undefined;
  const value = translations?.[props.currentUserLocale];

  return value && typeof value === 'object'
    ? (value as ContactSummaryTranslation)
    : null;
});
const hasTranslation = computed(() => translation.value !== null);

const displayProfileSummary = computed(() => {
  if (!hasTranslation.value || showOriginal.value) {
    return summary.value?.profile_summary ?? null;
  }

  return (
    translation.value?.profile_summary?.text ??
    summary.value?.profile_summary ??
    null
  );
});

function translatedList(
  field: 'open_issues' | 'preferences' | 'recent_topics',
): string[] {
  if (!hasTranslation.value || showOriginal.value) {
    return summary.value?.[field] ?? [];
  }

  const items = translation.value?.[field] ?? [];
  const texts = items
    .map((item) => item.text?.trim() ?? '')
    .filter((item) => item !== '');

  return texts.length > 0 ? texts : (summary.value?.[field] ?? []);
}

const displayOpenIssues = computed(() => translatedList('open_issues'));
const displayPreferences = computed(() => translatedList('preferences'));
const displayRecentTopics = computed(() => translatedList('recent_topics'));

const shouldQueueTranslation = computed(() => {
  const currentSummary = summary.value;
  if (
    !props.autoTranslateEnabled ||
    !props.canTranslate ||
    !props.contactProfile ||
    !currentSummary
  ) {
    return false;
  }

  if (
    currentSummary.source_locale &&
    localeMatches(currentSummary.source_locale, props.currentUserLocale)
  ) {
    return false;
  }

  return !hasTranslation.value;
});

function clearPendingTimer(): void {
  if (pendingTimer !== null) {
    window.clearTimeout(pendingTimer);
    pendingTimer = null;
  }
}

function markPending(): void {
  pending.value = true;
  clearPendingTimer();
  pendingTimer = window.setTimeout(() => {
    pending.value = false;
    pendingTimer = null;
  }, TRANSLATION_PENDING_TIMEOUT_MS);
}

async function queueTranslation(): Promise<void> {
  if (!shouldQueueTranslation.value || pending.value || !props.contactProfile) {
    return;
  }

  markPending();
  try {
    await axios.post(
      inboxActions.contacts.aiSummary.queueTranslation.url({
        contactId: props.contactProfile.id,
      }),
      { target_locale: props.currentUserLocale },
    );
  } catch {
    pending.value = false;
    clearPendingTimer();
  }
}

watch(
  () => [
    props.contactProfile?.id,
    props.currentUserLocale,
    props.autoTranslateEnabled,
    hasTranslation.value,
    summary.value?.updated_at,
  ],
  () => {
    if (hasTranslation.value) {
      pending.value = false;
      clearPendingTimer();
    }
    void queueTranslation();
  },
  { immediate: true },
);

watch(
  () => props.contactProfile?.id,
  () => {
    showOriginal.value = false;
  },
);

onUnmounted(() => clearPendingTimer());
</script>

<template>
  <div v-if="!summary" class="py-8 text-center text-xs text-muted-foreground">
    {{ t('暂无 AI 摘要') }}
  </div>
  <div v-else class="space-y-4">
    <div class="flex items-center justify-between gap-2">
      <div
        class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
      >
        <span class="font-medium text-foreground">{{ t('AI 摘要') }}</span>
        <LoaderCircle
          v-if="pending"
          class="size-3 animate-spin"
          :title="t('翻译中')"
        />
      </div>
      <button
        v-if="hasTranslation"
        type="button"
        class="shrink-0 text-xs text-muted-foreground hover:text-foreground"
        @click="showOriginal = !showOriginal"
      >
        {{ showOriginal ? t('显示译文') : t('显示原文') }}
      </button>
    </div>

    <section class="space-y-1.5">
      <h3 class="text-xs font-medium text-muted-foreground">
        {{ t('客户概览') }}
      </h3>
      <p class="leading-6 break-words whitespace-pre-wrap">
        {{ displayProfileSummary || t('暂无') }}
      </p>
    </section>

    <section class="space-y-1.5">
      <h3 class="text-xs font-medium text-muted-foreground">
        {{ t('待关注') }}
      </h3>
      <ul v-if="displayOpenIssues.length > 0" class="space-y-1.5">
        <li
          v-for="item in displayOpenIssues"
          :key="item"
          class="leading-6 break-words"
        >
          {{ item }}
        </li>
      </ul>
      <p v-else class="text-muted-foreground">{{ t('暂无') }}</p>
    </section>

    <section class="space-y-1.5">
      <h3 class="text-xs font-medium text-muted-foreground">
        {{ t('偏好') }}
      </h3>
      <ul v-if="displayPreferences.length > 0" class="space-y-1.5">
        <li
          v-for="item in displayPreferences"
          :key="item"
          class="leading-6 break-words"
        >
          {{ item }}
        </li>
      </ul>
      <p v-else class="text-muted-foreground">{{ t('暂无') }}</p>
    </section>

    <section class="space-y-1.5">
      <h3 class="text-xs font-medium text-muted-foreground">
        {{ t('近期主题') }}
      </h3>
      <ul v-if="displayRecentTopics.length > 0" class="space-y-1.5">
        <li
          v-for="item in displayRecentTopics"
          :key="item"
          class="leading-6 break-words"
        >
          {{ item }}
        </li>
      </ul>
      <p v-else class="text-muted-foreground">{{ t('暂无') }}</p>
    </section>
  </div>
</template>
