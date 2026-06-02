<!--
  文件说明：收件箱页面片段，承接收件箱列表、时间线和右侧上下文信息。
-->
<script setup lang="ts">
import Inbox from '@/actions/App/Actions/Inbox';
import InputError from '@/components/common/InputError.vue';
import PhoneDialCodeCombobox from '@/components/common/PhoneDialCodeCombobox.vue';
import TagSelector from '@/components/common/TagSelector.vue';
import AttributeFieldRenderer from '@/components/custom-attribute/AttributeFieldRenderer.vue';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import { EMAIL_MAX_LENGTH, isLikelyValidEmail } from '@/lib/email';
import {
  buildPhoneNumber,
  getDefaultPhonePrefix,
  isLikelyValidDialCode,
  isLikelyValidLocalPhone,
  isLikelyValidPhone,
  splitPhoneNumber,
} from '@/lib/phone';
import ContactAiSummaryPanel from '@/pages/inbox/ContactAiSummaryPanel.vue';
import workspace from '@/routes/workspace';
import type {
  ContactAttributeFieldData,
  ConversationSummaryData,
  EnumOptionData,
  FormCreateContactIdentityData,
  FormUpdateContactAttributeValuesData,
  FormUpdateContactData,
  InboxContactProfileData,
  TagOptionData,
  TelegramConversationChannelContextData,
  WebConversationChannelContextData,
} from '@/types/generated';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { Globe, MessageSquare, Send } from 'lucide-vue-next';
import {
  computed,
  nextTick,
  onUnmounted,
  reactive,
  ref,
  watch,
  type Component,
} from 'vue';

const props = defineProps<{
  contactProfile: InboxContactProfileData | null;
  conversation: ConversationSummaryData;
  availableContactTags: TagOptionData[];
  conversationId: string;
  visitorLocale: string;
  receptionLanguageOptions: EnumOptionData[];
  currentUserLocale: string;
  canTranslate: boolean;
  autoTranslateEnabled: boolean;
}>();

const { locale, t } = useI18n();
const { formatDateTime } = useDateTime();
const defaultPhonePrefix = computed(() => getDefaultPhonePrefix(locale.value));

type TabKey = 'profile' | 'ai_summary';

const tabs: Array<{ key: TabKey; label: string }> = [
  { key: 'profile', label: t('资料') },
  { key: 'ai_summary', label: t('AI 摘要') },
];

const activeTab = ref<TabKey>('profile');

// 渠道无关的会话信息头：渠道身份 + 状态 + 归属，任何渠道点开都有内容。
const channelIconMap: Record<string, Component> = {
  web: Globe,
  telegram: Send,
};
const channelIcon = computed<Component>(
  () => channelIconMap[props.conversation.channel_type ?? ''] ?? MessageSquare,
);
const channelDisplayName = computed(() => {
  const name = props.conversation.channel_name?.trim();
  return name && name.length > 0
    ? name
    : (props.conversation.channel_type_label ?? '—');
});
const conversationAssigneeDisplay = computed(
  () =>
    props.conversation.assigned_user_name ??
    props.conversation.inbox_status_label,
);

// 多态渠道上下文：按 channel_type 收窄成对应变体，再铺成统一的「标签 / 值（可选链接）」行。
type ChannelContextRow = {
  key: string;
  label: string;
  value: string;
  href?: string;
};

const webContext = computed<WebConversationChannelContextData | null>(() => {
  const ctx = props.conversation.channel_context;
  return ctx && ctx.channel_type === 'web'
    ? (ctx as WebConversationChannelContextData)
    : null;
});
const telegramContext = computed<TelegramConversationChannelContextData | null>(
  () => {
    const ctx = props.conversation.channel_context;
    return ctx && ctx.channel_type === 'telegram'
      ? (ctx as TelegramConversationChannelContextData)
      : null;
  },
);

const channelContextTitle = computed(() => {
  if (webContext.value) return t('来源与设备');
  if (telegramContext.value) return t('Telegram 信息');
  return '';
});

const channelContextRows = computed<ChannelContextRow[]>(() => {
  const rows: ChannelContextRow[] = [];
  const web = webContext.value;
  if (web) {
    const browser = [web.browser, web.browser_version]
      .filter(Boolean)
      .join(' ');
    if (web.current_url) {
      rows.push({
        key: 'current_url',
        label: t('当前页'),
        value: web.current_url,
        href: web.current_url,
      });
    }
    if (web.referrer) {
      rows.push({
        key: 'referrer',
        label: t('来源页'),
        value: web.referrer,
        href: web.referrer,
      });
    }
    if (web.landing_url) {
      rows.push({
        key: 'landing_url',
        label: t('落地页'),
        value: web.landing_url,
        href: web.landing_url,
      });
    }
    if (web.device_type) {
      rows.push({ key: 'device', label: t('设备'), value: web.device_type });
    }
    if (browser) {
      rows.push({ key: 'browser', label: t('浏览器'), value: browser });
    }
    if (web.platform) {
      rows.push({ key: 'platform', label: t('操作系统'), value: web.platform });
    }
    return rows;
  }

  const tg = telegramContext.value;
  if (tg) {
    if (tg.username) {
      rows.push({
        key: 'username',
        label: t('用户名'),
        value: tg.username.startsWith('@') ? tg.username : `@${tg.username}`,
      });
    }
    if (tg.language_code) {
      rows.push({ key: 'language', label: t('语言'), value: tg.language_code });
    }
    if (tg.chat_type) {
      rows.push({
        key: 'chat_type',
        label: t('会话类型'),
        value: tg.chat_type,
      });
    }
    if (tg.is_premium !== null) {
      rows.push({
        key: 'premium',
        // Telegram 专有功能名，中英文一致，不走 i18n。
        label: 'Premium',
        value: tg.is_premium ? t('是') : t('否'),
      });
    }
  }
  return rows;
});
const profileForm = useForm<FormUpdateContactData>({
  name: '',
  type: null,
  note: null,
  country: '',
  city: '',
});
const noteForm = useForm({
  note: props.contactProfile?.note ?? '',
});
const emailForm = useForm<FormCreateContactIdentityData>({
  type: 'email',
  value: '',
  namespace: null,
});
const phoneForm = useForm<FormCreateContactIdentityData>({
  type: 'phone',
  value: '',
  namespace: null,
});
const phoneDialCode = ref(defaultPhonePrefix.value);
const phoneLocalNumber = ref('');
const typeForm = useForm<FormUpdateContactData>({
  name: null,
  type: props.contactProfile?.type ?? null,
  note: null,
  country: null,
  city: null,
});
const attrForm = useForm<FormUpdateContactAttributeValuesData>({
  attributes: {},
});
const attrValues = reactive<Record<string, unknown>>({});
const attrSaving = ref(false);
const tagProcessing = ref(false);
const syncingFromProps = ref(false);
const lastSavedProfile = ref('');
const lastSavedEmail = ref(props.contactProfile?.primary_email ?? '');
const lastSavedPhone = ref(props.contactProfile?.primary_phone ?? '');
const lastSavedNote = ref(props.contactProfile?.note ?? '');
const lastSavedAttributes = ref('');

/** 自动保存失败由全局 toast 处理。 */
let profileSaveTimer: number | null = null;
let emailSaveTimer: number | null = null;
let phoneSaveTimer: number | null = null;
let noteSaveTimer: number | null = null;
let attributeSaveTimer: number | null = null;

watch(
  () => [
    props.contactProfile?.id,
    props.contactProfile?.name,
    props.contactProfile?.type,
    props.contactProfile?.country,
    props.contactProfile?.city,
    props.contactProfile?.primary_email,
    props.contactProfile?.primary_phone,
    props.contactProfile?.note,
  ],
  () => {
    syncingFromProps.value = true;
    profileForm.name = props.contactProfile?.name ?? '';
    profileForm.type = null;
    profileForm.country = props.contactProfile?.country ?? '';
    profileForm.city = props.contactProfile?.city ?? '';
    typeForm.type = props.contactProfile?.type ?? null;
    typeForm.clearErrors();
    lastSavedProfile.value = serializeProfileForm();
    emailForm.value = props.contactProfile?.primary_email ?? '';
    const parsedPhone = splitPhoneNumber(
      props.contactProfile?.primary_phone ?? '',
    );
    phoneDialCode.value = parsedPhone.dialCode || defaultPhonePrefix.value;
    phoneLocalNumber.value = parsedPhone.localNumber;
    phoneForm.value = props.contactProfile?.primary_phone ?? '';
    lastSavedEmail.value = props.contactProfile?.primary_email ?? '';
    lastSavedPhone.value = props.contactProfile?.primary_phone ?? '';
    noteForm.note = props.contactProfile?.note ?? '';
    lastSavedNote.value = props.contactProfile?.note ?? '';
    profileForm.clearErrors();
    emailForm.clearErrors();
    phoneForm.clearErrors();
    noteForm.clearErrors();
    void nextTick(() => {
      syncingFromProps.value = false;
    });
  },
  { immediate: true },
);

watch(
  () => [props.contactProfile?.id, props.contactProfile?.custom_attributes],
  () => {
    initAttrValues(props.contactProfile?.custom_attributes ?? []);
  },
  { immediate: true },
);

const visitorLocaleInput = ref(props.visitorLocale);
const visitorLocaleSaving = ref(false);
const visitorLocaleError = ref<string | null>(null);
const visitorLocaleOptions = computed(() => {
  const options = props.receptionLanguageOptions.map((option) => ({
    value: String(option.value),
    label: option.label,
  }));

  return options;
});

watch(
  () => props.visitorLocale,
  (newVal) => {
    visitorLocaleInput.value = newVal;
    visitorLocaleError.value = null;
  },
);

function saveVisitorLocale(nextValue: string): void {
  if (visitorLocaleSaving.value) return;
  visitorLocaleInput.value = nextValue;
  if (nextValue === props.visitorLocale) {
    visitorLocaleError.value = null;
    return;
  }

  visitorLocaleSaving.value = true;
  visitorLocaleError.value = null;
  router.put(
    Inbox.UpdateConversationVisitorLocaleAction({
      conversation: props.conversationId,
    }).url,
    { visitor_locale: nextValue },
    {
      preserveState: true,
      preserveScroll: true,
      only: ['selection', 'conversation_list'],
      onError: (errors) => {
        visitorLocaleInput.value = props.visitorLocale;
        visitorLocaleError.value = errors.visitor_locale ?? t('保存失败');
      },
      onFinish: () => {
        visitorLocaleSaving.value = false;
      },
    },
  );
}

function placeholderOr(value: string | null | undefined): string {
  const trimmed = value?.toString().trim();
  return trimmed && trimmed.length > 0 ? trimmed : '—';
}

type ProfileRow = { key: string; label: string; value: string };

const profileRows = computed<ProfileRow[]>(() => {
  const profile = props.contactProfile;
  if (!profile) return [];

  const rows: ProfileRow[] = [
    { key: 'source', label: t('来源'), value: profile.source_label },
    {
      key: 'important',
      label: t('重点客户'),
      value: profile.is_important ? t('是') : t('否'),
    },
    { key: 'type', label: t('类型'), value: profile.type_label },
    { key: 'name', label: t('姓名'), value: placeholderOr(profile.name) },
    {
      key: 'email',
      label: t('邮箱'),
      value: placeholderOr(profile.primary_email),
    },
    {
      key: 'phone',
      label: t('手机号'),
      value: placeholderOr(profile.primary_phone),
    },
    { key: 'locale', label: t('语言'), value: placeholderOr(profile.locale) },
    {
      key: 'visitor_locale',
      label: t('访客语言'),
      value: placeholderOr(props.visitorLocale),
    },
    {
      key: 'timezone',
      label: t('时区'),
      value: placeholderOr(profile.timezone),
    },
    {
      key: 'region',
      label: t('地区'),
      value: placeholderOr(
        [profile.country, profile.city].filter(Boolean).join(' / '),
      ),
    },
    { key: 'note', label: t('备注'), value: '' },
    {
      key: 'last_seen_at',
      label: t('最近活跃'),
      value: profile.last_seen_at
        ? formatDateTime(profile.last_seen_at, 'YYYY-MM-DD HH:mm')
        : '—',
    },
    {
      key: 'created_at',
      label: t('创建时间'),
      value: profile.created_at
        ? formatDateTime(profile.created_at, 'YYYY-MM-DD HH:mm')
        : '—',
    },
  ];

  return rows;
});

const selectedTagIds = computed(() =>
  (props.contactProfile?.tags ?? []).map((tag) => tag.id),
);

// 咨询概况：该联系人所有会话上的会话标签去重计数，只读展示。
const conversationTagAggregates = computed(
  () => props.contactProfile?.conversation_tag_aggregates ?? [],
);

const editableAttributes = computed(() => {
  return (props.contactProfile?.custom_attributes ?? []).filter(
    (field) => field.is_editable,
  );
});

const deletedAttributes = computed(() => {
  return (props.contactProfile?.custom_attributes ?? []).filter(
    (field) =>
      !field.is_editable && field.value !== null && field.value !== undefined,
  );
});

const phoneErrorMessage = computed(() => {
  const phone = phoneLocalNumber.value.trim();

  if (phone === '') {
    return phoneForm.errors.value;
  }

  if (
    !isLikelyValidDialCode(phoneDialCode.value) ||
    !isLikelyValidLocalPhone(phone) ||
    !isLikelyValidPhone(buildPhoneNumber(phoneDialCode.value, phone))
  ) {
    return t('请输入有效的手机号');
  }

  return phoneForm.errors.value;
});

const isPhoneInvalid = computed(() => {
  const phone = phoneLocalNumber.value.trim();

  if (phone === '') {
    return false;
  }

  return (
    !isLikelyValidDialCode(phoneDialCode.value) ||
    !isLikelyValidLocalPhone(phone) ||
    !isLikelyValidPhone(buildPhoneNumber(phoneDialCode.value, phone))
  );
});

const emailErrorMessage = computed(() => {
  const email = emailForm.value.trim();

  if (email === '') {
    return emailForm.errors.value;
  }

  if (!isLikelyValidEmail(email)) {
    return t('请输入有效的邮箱地址');
  }

  return emailForm.errors.value;
});

const isEmailInvalid = computed(() => {
  const email = emailForm.value.trim();

  if (email === '') {
    return false;
  }

  return !isLikelyValidEmail(email);
});

const hasCustomAttributes = computed(() => {
  return (props.contactProfile?.custom_attributes ?? []).length > 0;
});

function clearNoteSaveTimer(): void {
  if (noteSaveTimer) {
    window.clearTimeout(noteSaveTimer);
    noteSaveTimer = null;
  }
}

function clearProfileSaveTimer(): void {
  if (profileSaveTimer) {
    window.clearTimeout(profileSaveTimer);
    profileSaveTimer = null;
  }
}

function clearEmailSaveTimer(): void {
  if (emailSaveTimer) {
    window.clearTimeout(emailSaveTimer);
    emailSaveTimer = null;
  }
}

function clearPhoneSaveTimer(): void {
  if (phoneSaveTimer) {
    window.clearTimeout(phoneSaveTimer);
    phoneSaveTimer = null;
  }
}

function clearAttributeSaveTimer(): void {
  if (attributeSaveTimer) {
    window.clearTimeout(attributeSaveTimer);
    attributeSaveTimer = null;
  }
}

function serializeProfileForm(): string {
  return JSON.stringify({
    name: profileForm.name ?? '',
    country: profileForm.country ?? '',
    city: profileForm.city ?? '',
  });
}

function reloadSelection(): void {
  router.reload({
    only: ['selection', 'conversation_list'],
  });
}

function initAttrValues(fields: ContactAttributeFieldData[]): void {
  syncingFromProps.value = true;

  for (const key of Object.keys(attrValues)) {
    delete attrValues[key];
  }

  for (const field of fields) {
    attrValues[field.key] =
      field.value ?? (field.type === 'multi_select' ? [] : null);
  }

  attrForm.attributes = { ...attrValues };
  lastSavedAttributes.value = JSON.stringify(attrForm.attributes);
  attrForm.clearErrors();
  void nextTick(() => {
    syncingFromProps.value = false;
  });
}

function saveProfile(showProgress = true): void {
  const profile = props.contactProfile;
  if (!profile) return;
  if (profileForm.processing) {
    scheduleProfileSave();
    return;
  }

  const serializedProfile = serializeProfileForm();
  if (serializedProfile === lastSavedProfile.value) return;

  profileForm.put(
    workspace.contacts.update.url({
      id: profile.id,
    }),
    {
      preserveScroll: true,
      only: ['selection', 'conversation_list'],
      showProgress,
      onSuccess: () => {
        lastSavedProfile.value = serializedProfile;
      },
    },
  );
}

function scheduleProfileSave(): void {
  if (syncingFromProps.value) return;

  clearProfileSaveTimer();
  profileSaveTimer = window.setTimeout(() => {
    profileSaveTimer = null;
    saveProfile(false);
  }, 700);
}

function saveNote(showProgress = true): void {
  const profile = props.contactProfile;
  if (!profile) return;
  if (noteForm.processing) {
    scheduleNoteSave();
    return;
  }
  if (noteForm.note === lastSavedNote.value) return;

  noteForm.put(
    workspace.contacts.update.url({
      id: profile.id,
    }),
    {
      preserveScroll: true,
      only: ['selection', 'conversation_list'],
      onSuccess: () => {
        lastSavedNote.value = noteForm.note;
      },
      showProgress,
    },
  );
}

function saveIdentity(kind: 'email' | 'phone', showProgress = true): void {
  const profile = props.contactProfile;
  if (!profile) return;

  const form = kind === 'email' ? emailForm : phoneForm;
  const lastSaved = kind === 'email' ? lastSavedEmail : lastSavedPhone;
  const identityId =
    kind === 'email'
      ? profile.primary_email_identity_id
      : profile.primary_phone_identity_id;
  const value =
    kind === 'phone'
      ? buildPhoneNumber(phoneDialCode.value, phoneLocalNumber.value)
      : form.value.trim();

  if (form.processing) {
    scheduleIdentitySave(kind);
    return;
  }
  if (value === lastSaved.value) return;

  if (value === '') {
    if (!identityId) {
      return;
    }

    form.clearErrors('value');
    form.delete(
      workspace.contacts.identities.destroy.url({
        contactId: profile.id,
        identityId,
      }),
      {
        preserveScroll: true,
        only: ['selection', 'conversation_list'],
        showProgress,
        onSuccess: () => {
          lastSaved.value = '';
        },
      },
    );
    return;
  }

  if (kind === 'phone' && isPhoneInvalid.value) {
    form.setError('value', t('请输入有效的手机号'));
    return;
  }
  if (kind === 'email' && isEmailInvalid.value) {
    form.setError('value', t('请输入有效的邮箱地址'));
    return;
  }

  form.clearErrors('value');
  form.value = value;
  form.type = kind;
  form.namespace = null;

  const options = {
    preserveScroll: true,
    only: ['selection', 'conversation_list'],
    showProgress,
    onSuccess: () => {
      lastSaved.value = value;
    },
  };

  if (identityId) {
    form.put(
      workspace.contacts.identities.replace.url({
        contactId: profile.id,
        identityId,
      }),
      options,
    );
    return;
  }

  form.post(
    workspace.contacts.identities.store.url({
      contactId: profile.id,
    }),
    options,
  );
}

function scheduleIdentitySave(kind: 'email' | 'phone'): void {
  if (syncingFromProps.value) return;

  const clearTimer =
    kind === 'email' ? clearEmailSaveTimer : clearPhoneSaveTimer;
  clearTimer();
  const timer = window.setTimeout(() => {
    if (kind === 'email') {
      emailSaveTimer = null;
    } else {
      phoneSaveTimer = null;
    }
    saveIdentity(kind, false);
  }, 700);

  if (kind === 'email') {
    emailSaveTimer = timer;
  } else {
    phoneSaveTimer = timer;
  }
}

function saveContactType(nextType: string): void {
  const profile = props.contactProfile;
  if (!profile || typeForm.processing) return;
  if (nextType !== 'visitor' && nextType !== 'contact') return;
  if (nextType === profile.type) return;

  typeForm.type = nextType;
  typeForm.put(
    workspace.contacts.update.url({
      id: profile.id,
    }),
    {
      preserveScroll: true,
      only: ['selection', 'conversation_list'],
    },
  );
}

function scheduleNoteSave(): void {
  if (syncingFromProps.value) return;

  clearNoteSaveTimer();
  noteSaveTimer = window.setTimeout(() => {
    noteSaveTimer = null;
    saveNote(false);
  }, 700);
}

async function handleAttachTag(tagId: string): Promise<void> {
  const profile = props.contactProfile;
  if (!profile || tagProcessing.value) return;

  tagProcessing.value = true;
  try {
    await axios.post(
      workspace.contacts.tags.attach.url({
        id: profile.id,
      }),
      { tag_id: tagId },
    );
    reloadSelection();
  } finally {
    tagProcessing.value = false;
  }
}

async function handleDetachTag(tagId: string): Promise<void> {
  const profile = props.contactProfile;
  if (!profile || tagProcessing.value) return;

  tagProcessing.value = true;
  try {
    await axios.delete(
      workspace.contacts.tags.detach.url({
        id: profile.id,
        tagId,
      }),
    );
    reloadSelection();
  } finally {
    tagProcessing.value = false;
  }
}

function saveCustomAttributes(showProgress = true): void {
  const profile = props.contactProfile;
  if (!profile) return;
  if (attrSaving.value || attrForm.processing) {
    scheduleAttributeSave();
    return;
  }

  attrSaving.value = true;
  attrForm.attributes = { ...attrValues };
  const serializedAttributes = JSON.stringify(attrForm.attributes);
  if (serializedAttributes === lastSavedAttributes.value) {
    attrSaving.value = false;
    return;
  }

  attrForm.put(
    workspace.contacts.attributes.update.url({
      id: profile.id,
    }),
    {
      preserveScroll: true,
      only: ['selection', 'conversation_list'],
      showProgress,
      onSuccess: () => {
        lastSavedAttributes.value = serializedAttributes;
      },
      onFinish: () => {
        attrSaving.value = false;
      },
    },
  );
}

function attrFieldError(key: string): string | undefined {
  const errors = attrForm.errors as Record<string, string | undefined>;

  return errors[`attributes.${key}`];
}

function scheduleAttributeSave(): void {
  if (syncingFromProps.value) return;

  clearAttributeSaveTimer();
  attributeSaveTimer = window.setTimeout(() => {
    attributeSaveTimer = null;
    saveCustomAttributes(false);
  }, 700);
}

function customAttributeOptionLabel(
  field: ContactAttributeFieldData,
  code: string,
): string {
  const options = field.config?.options as
    | Array<{ code: string; label: string }>
    | undefined;

  return options?.find((option) => option.code === code)?.label ?? code;
}

function formatCustomAttributeValue(field: ContactAttributeFieldData): string {
  if (field.value === null || field.value === undefined || field.value === '') {
    return '-';
  }

  if (field.type === 'boolean') {
    return field.value === true ? t('是') : t('否');
  }

  if (field.type === 'single_select' && typeof field.value === 'string') {
    return customAttributeOptionLabel(field, field.value);
  }

  if (field.type === 'multi_select' && Array.isArray(field.value)) {
    return field.value
      .map((code) => customAttributeOptionLabel(field, String(code)))
      .join(', ');
  }

  return String(field.value);
}

watch(
  () => [profileForm.name, profileForm.country, profileForm.city],
  () => scheduleProfileSave(),
);

watch(
  () => emailForm.value,
  () => scheduleIdentitySave('email'),
);

watch(
  () => [phoneDialCode.value, phoneLocalNumber.value],
  () => scheduleIdentitySave('phone'),
);

watch(defaultPhonePrefix, (value) => {
  if (phoneLocalNumber.value.trim() !== '') return;

  phoneDialCode.value = value;
});

watch(
  () => noteForm.note,
  () => scheduleNoteSave(),
);

watch(attrValues, () => scheduleAttributeSave(), { deep: true });

onUnmounted(() => {
  clearProfileSaveTimer();
  clearEmailSaveTimer();
  clearPhoneSaveTimer();
  clearNoteSaveTimer();
  clearAttributeSaveTimer();
});
</script>

<template>
  <aside
    class="flex min-h-0 w-full min-w-0 flex-col overflow-x-visible overflow-y-hidden bg-background"
  >
    <div class="shrink-0 space-y-1.5 border-b px-3 py-2.5">
      <div class="flex items-center gap-2">
        <component
          :is="channelIcon"
          class="h-4 w-4 shrink-0 text-muted-foreground"
        />
        <span class="min-w-0 truncate text-sm font-medium text-foreground">
          {{ channelDisplayName }}
        </span>
      </div>
      <div
        class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
      >
        <span>
          {{ t('状态') }}：<span class="text-foreground">{{
            conversation.status_label
          }}</span>
        </span>
        <span>
          {{ t('负责人') }}：<span class="text-foreground">{{
            conversationAssigneeDisplay
          }}</span>
        </span>
      </div>
    </div>

    <div class="flex shrink-0 items-center gap-1 border-b px-3 py-2">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="rounded px-2.5 py-1 text-xs transition-colors"
        :class="
          activeTab === tab.key
            ? 'bg-primary text-primary-foreground'
            : 'text-muted-foreground hover:bg-muted'
        "
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <div
      class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto p-3 text-sm"
    >
      <template v-if="activeTab === 'profile'">
        <!-- 多态渠道上下文：Web 访客来源/设备 或 Telegram 用户信息；无采集时整体隐藏 -->
        <div v-if="channelContextRows.length > 0" class="mb-4 space-y-2">
          <div class="text-xs font-medium text-muted-foreground">
            {{ channelContextTitle }}
          </div>
          <dl class="space-y-2">
            <div
              v-for="row in channelContextRows"
              :key="row.key"
              class="grid grid-cols-[4.75rem_minmax(0,1fr)] items-start gap-2"
            >
              <dt
                class="flex min-h-7 min-w-0 items-center text-xs text-muted-foreground"
              >
                {{ row.label }}
              </dt>
              <dd class="flex min-h-7 min-w-0 flex-1 items-center">
                <a
                  v-if="row.href"
                  :href="row.href"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="truncate text-sm text-foreground underline-offset-2 hover:underline"
                  :title="row.value"
                >
                  {{ row.value }}
                </a>
                <span
                  v-else
                  class="truncate text-sm text-foreground"
                  :title="row.value"
                >
                  {{ row.value }}
                </span>
              </dd>
            </div>
          </dl>
          <Separator />
        </div>

        <div
          v-if="!contactProfile"
          class="py-8 text-center text-xs text-muted-foreground"
        >
          {{ t('暂无联系人资料') }}
        </div>
        <div v-else class="space-y-4">
          <dl class="space-y-2">
            <div
              v-for="row in profileRows"
              :key="row.key"
              class="grid grid-cols-[4.75rem_minmax(0,1fr)] items-start gap-2"
            >
              <dt
                class="flex min-h-8 min-w-0 items-center text-xs text-muted-foreground"
              >
                {{ row.label }}
              </dt>
              <dd v-if="row.key === 'name'" class="min-w-0 flex-1 space-y-1">
                <Input
                  v-model="profileForm.name"
                  type="text"
                  maxlength="255"
                  :disabled="profileForm.processing"
                  class="h-8 px-2.5 text-sm"
                />
                <InputError :message="profileForm.errors.name" />
              </dd>
              <dd v-else-if="row.key === 'type'" class="min-w-0 flex-1">
                <Select
                  :model-value="contactProfile.type"
                  :disabled="typeForm.processing"
                  @update:model-value="
                    (value) => saveContactType(String(value))
                  "
                >
                  <SelectTrigger class="h-8 w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="visitor">{{ t('访客') }}</SelectItem>
                    <SelectItem value="contact">{{ t('联系人') }}</SelectItem>
                  </SelectContent>
                </Select>
              </dd>
              <dd
                v-else-if="row.key === 'email'"
                class="relative min-w-0 flex-1 space-y-1"
              >
                <Input
                  v-model="emailForm.value"
                  type="email"
                  inputmode="email"
                  autocomplete="email"
                  :maxlength="EMAIL_MAX_LENGTH"
                  :disabled="emailForm.processing"
                  class="h-8 px-2.5 text-sm"
                />
                <InputError :message="emailErrorMessage" />
              </dd>
              <dd
                v-else-if="row.key === 'phone'"
                class="relative min-w-0 flex-1 space-y-1"
              >
                <div class="flex min-w-0 flex-wrap gap-2">
                  <PhoneDialCodeCombobox
                    v-model="phoneDialCode"
                    align="end"
                    portal
                    class="h-8 w-28 shrink-0 text-xs"
                    :disabled="phoneForm.processing"
                  />
                  <Input
                    v-model="phoneLocalNumber"
                    type="tel"
                    inputmode="tel"
                    :disabled="phoneForm.processing"
                    class="h-8 min-w-0 flex-1 px-2.5 text-sm"
                  />
                </div>
                <InputError :message="phoneErrorMessage" />
              </dd>
              <dd
                v-else-if="row.key === 'region'"
                class="grid min-w-0 flex-1 grid-cols-[repeat(auto-fit,minmax(7rem,1fr))] gap-2"
              >
                <div class="min-w-0 space-y-1">
                  <Input
                    v-model="profileForm.country"
                    type="text"
                    maxlength="120"
                    :disabled="profileForm.processing"
                    class="h-8 px-2.5 text-sm"
                  />
                  <InputError :message="profileForm.errors.country" />
                </div>
                <div class="min-w-0 space-y-1">
                  <Input
                    v-model="profileForm.city"
                    type="text"
                    maxlength="120"
                    :disabled="profileForm.processing"
                    class="h-8 px-2.5 text-sm"
                  />
                  <InputError :message="profileForm.errors.city" />
                </div>
              </dd>
              <dd
                v-else-if="row.key === 'visitor_locale'"
                class="min-w-0 flex-1 space-y-1"
              >
                <Select
                  :model-value="visitorLocaleInput"
                  :disabled="visitorLocaleSaving"
                  @update:model-value="saveVisitorLocale(String($event))"
                >
                  <SelectTrigger class="h-8 w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem
                      v-for="option in visitorLocaleOptions"
                      :key="option.value"
                      :value="option.value"
                    >
                      {{ option.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
                <InputError :message="visitorLocaleError" />
              </dd>
              <dd
                v-else-if="row.key !== 'note'"
                class="flex min-h-8 min-w-0 flex-1 items-center text-sm break-words text-foreground"
              >
                {{ row.value }}
              </dd>
              <dd v-else class="relative min-w-0 flex-1 space-y-1.5">
                <Textarea
                  v-model="noteForm.note"
                  rows="2"
                  maxlength="10000"
                  :disabled="noteForm.processing"
                  class="resize-y leading-6"
                />
                <InputError :message="noteForm.errors.note" />
              </dd>
            </div>
          </dl>

          <Separator />

          <div class="grid grid-cols-[4.75rem_minmax(0,1fr)] items-start gap-2">
            <div
              class="flex min-h-8 min-w-0 items-center text-xs text-muted-foreground"
            >
              {{ t('标签') }}
            </div>
            <div class="min-w-0 flex-1">
              <TagSelector
                :options="availableContactTags"
                :selected-tag-ids="selectedTagIds"
                :disabled="tagProcessing"
                @attach="handleAttachTag"
                @detach="handleDetachTag"
              />
            </div>
          </div>

          <!-- 咨询概况：跨该联系人所有会话的会话标签聚合，只读 -->
          <div
            v-if="conversationTagAggregates.length > 0"
            class="grid grid-cols-[4.75rem_minmax(0,1fr)] items-start gap-2"
          >
            <div
              class="flex min-h-8 min-w-0 items-center text-xs text-muted-foreground"
            >
              {{ t('咨询概况') }}
            </div>
            <div class="flex min-w-0 flex-1 flex-wrap gap-1.5">
              <span
                v-for="aggregate in conversationTagAggregates"
                :key="aggregate.tag_id"
                class="flex items-center gap-1 rounded-full border bg-background py-0.5 pr-2 pl-2 text-xs text-foreground"
              >
                <span
                  class="h-1.5 w-1.5 shrink-0 rounded-full"
                  :style="{ backgroundColor: aggregate.color ?? '#94a3b8' }"
                />
                {{ aggregate.name }}
                <span class="text-muted-foreground"
                  >×{{ aggregate.count }}</span
                >
              </span>
            </div>
          </div>

          <template v-if="hasCustomAttributes">
            <Separator />
            <div class="space-y-3">
              <div
                v-for="field in editableAttributes"
                :key="field.definition_id"
                class="grid grid-cols-[4.75rem_minmax(0,1fr)] items-start gap-2"
              >
                <div
                  class="flex min-h-8 min-w-0 items-center text-xs text-muted-foreground"
                >
                  {{ field.name }}
                </div>
                <div class="min-w-0 flex-1">
                  <AttributeFieldRenderer
                    :field="field"
                    :model-value="attrValues[field.key]"
                    :errors="attrFieldError(field.key)"
                    :disabled="attrSaving"
                    hide-label
                    hide-meta
                    compact
                    @update:model-value="attrValues[field.key] = $event"
                  />
                </div>
              </div>
              <div
                v-if="deletedAttributes.length > 0"
                :class="{ 'pt-2': editableAttributes.length > 0 }"
                class="space-y-2"
              >
                <div
                  v-for="field in deletedAttributes"
                  :key="field.definition_id"
                  class="grid grid-cols-[4.75rem_minmax(0,1fr)] items-start gap-2"
                >
                  <div
                    class="flex min-h-8 min-w-0 items-center text-xs text-muted-foreground"
                  >
                    {{ field.name }}
                  </div>
                  <div class="flex min-h-8 min-w-0 flex-1 items-center">
                    <div class="truncate text-sm">
                      {{ formatCustomAttributeValue(field) }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </template>
          <div
            v-else
            class="grid grid-cols-[4.75rem_minmax(0,1fr)] items-start gap-2"
          >
            <div
              class="flex min-h-8 min-w-0 items-center text-xs text-muted-foreground"
            >
              {{ t('自定义属性') }}
            </div>
            <div
              class="flex min-h-8 min-w-0 flex-1 items-center text-xs text-muted-foreground"
            >
              {{ t('暂无自定义属性') }}
            </div>
          </div>
        </div>
      </template>

      <template v-else-if="activeTab === 'ai_summary'">
        <ContactAiSummaryPanel
          :contact-profile="contactProfile"
          :current-user-locale="currentUserLocale"
          :can-translate="canTranslate"
          :auto-translate-enabled="autoTranslateEnabled"
        />
      </template>
    </div>
  </aside>
</template>
