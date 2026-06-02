<!--
  文件说明：联系人模块页面，承接联系人列表、详情抽屉、会话记录和筛选交互。
-->
<script setup lang="ts">
import InputError from '@/components/common/InputError.vue';
import PhoneDialCodeCombobox from '@/components/common/PhoneDialCodeCombobox.vue';
import TagSelector from '@/components/common/TagSelector.vue';
import AttributeFieldRenderer from '@/components/custom-attribute/AttributeFieldRenderer.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
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
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import { useVisitorDisplay } from '@/composables/useVisitorDisplay';
import { EMAIL_MAX_LENGTH, isLikelyValidEmail } from '@/lib/email';
import { getAvatarInitial } from '@/lib/initials';
import {
  buildPhoneNumber,
  getDefaultPhonePrefix,
  isLikelyValidDialCode,
  isLikelyValidLocalPhone,
  isLikelyValidPhone,
  splitPhoneNumber,
} from '@/lib/phone';
import workspace from '@/routes/workspace';
import type {
  ContactAttributeFieldData,
  ContactDetailData,
  ContactIdentityData,
  FormCreateContactIdentityData,
  FormReplaceContactIdentityData,
  FormUpdateContactAttributeValuesData,
  FormUpdateContactData,
  TagOptionData,
} from '@/types/generated';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { Star } from 'lucide-vue-next';
import {
  computed,
  nextTick,
  onMounted,
  onUnmounted,
  reactive,
  ref,
  watch,
} from 'vue';

const props = defineProps<{
  contactId: string;
  canMerge?: boolean;
  readOnly?: boolean;
  canRestore?: boolean;
  restoreProcessing?: boolean;
  includeTrashed?: boolean;
  availableTags?: TagOptionData[];
}>();

const emit = defineEmits<{
  requestMerge: [];
  requestRestore: [];
}>();

const { locale, t } = useI18n();
const { formatDateTime } = useDateTime();
const { formatVisitorName } = useVisitorDisplay();

const contactDetail = ref<ContactDetailData | null>(null);
const loading = ref(false);
const detailError = ref('');
const editOpen = ref(false);
const addIdentityOpen = ref(false);
const replacingIdentity = ref<ContactIdentityData | null>(null);
const deletingIdentity = ref<ContactIdentityData | null>(null);
const defaultPhonePrefix = computed(() => getDefaultPhonePrefix(locale.value));
const identityPhoneDialCode = ref(defaultPhonePrefix.value);
const identityPhoneLocalNumber = ref('');
const replacePhoneDialCode = ref(defaultPhonePrefix.value);
const replacePhoneLocalNumber = ref('');

const editForm = useForm<FormUpdateContactData>({
  name: null,
  type: null,
  note: null,
  country: null,
  city: null,
});
const attrForm = useForm<FormUpdateContactAttributeValuesData>({
  attributes: {},
});

const identityForm = useForm<FormCreateContactIdentityData>({
  type: 'email',
  value: '',
  namespace: null,
});
const replaceIdentityForm = useForm<FormReplaceContactIdentityData>({
  value: '',
});
const deleteIdentityForm = useForm({});

const attrValues = reactive<Record<string, unknown>>({});
const attrSaving = ref(false);
const importanceProcessing = ref(false);
const syncingAttributesFromDetail = ref(false);
const lastSavedAttributes = ref('');
let attributeSaveTimer: number | null = null;

const clearAttributeSaveTimer = () => {
  if (attributeSaveTimer) {
    window.clearTimeout(attributeSaveTimer);
    attributeSaveTimer = null;
  }
};

const initAttrValues = (fields: ContactAttributeFieldData[]) => {
  clearAttributeSaveTimer();
  syncingAttributesFromDetail.value = true;

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
    syncingAttributesFromDetail.value = false;
  });
};

const editableAttributes = computed(() => {
  return (contactDetail.value?.custom_attributes ?? []).filter(
    (f) => f.is_editable,
  );
});

const deletedAttributes = computed(() => {
  return (contactDetail.value?.custom_attributes ?? []).filter(
    (f) => !f.is_editable && f.value !== null && f.value !== undefined,
  );
});

const hasCustomAttributes = computed(() => {
  return (contactDetail.value?.custom_attributes ?? []).length > 0;
});

const saveCustomAttributes = (silent = false) => {
  if (props.readOnly) {
    return;
  }

  if (attrSaving.value || attrForm.processing) {
    scheduleCustomAttributesSave();
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
      id: props.contactId,
    }),
    {
      preserveScroll: true,
      showProgress: !silent,
      onSuccess: () => {
        lastSavedAttributes.value = serializedAttributes;
        fetchDetail(props.contactId, true);
      },
      onFinish: () => {
        attrSaving.value = false;
      },
    },
  );
};

const scheduleCustomAttributesSave = () => {
  if (props.readOnly || syncingAttributesFromDetail.value) {
    return;
  }

  clearAttributeSaveTimer();
  attributeSaveTimer = window.setTimeout(() => {
    attributeSaveTimer = null;
    saveCustomAttributes(true);
  }, 700);
};

const attrFieldError = (key: string): string | undefined => {
  const errors = attrForm.errors as Record<string, string | undefined>;

  return errors[`attributes.${key}`];
};

const identityTypeOptions = [
  { value: 'email', label: t('邮箱') },
  { value: 'phone', label: t('手机号') },
];

const identityValueErrorMessage = computed(() => {
  if (identityForm.type === 'phone') {
    const phone = identityPhoneLocalNumber.value.trim();

    if (phone === '') {
      return identityForm.errors.value;
    }

    if (
      !isLikelyValidDialCode(identityPhoneDialCode.value) ||
      !isLikelyValidLocalPhone(phone) ||
      !isLikelyValidPhone(buildPhoneNumber(identityPhoneDialCode.value, phone))
    ) {
      return t('请输入有效的手机号');
    }

    return identityForm.errors.value;
  }

  if (identityForm.type === 'email') {
    const email = identityForm.value.trim();

    if (email === '') {
      return identityForm.errors.value;
    }

    if (!isLikelyValidEmail(email)) {
      return t('请输入有效的邮箱地址');
    }

    return identityForm.errors.value;
  }

  return identityForm.errors.value;
});

const isIdentityValueInvalid = computed(() => {
  if (identityForm.type === 'phone') {
    const phone = identityPhoneLocalNumber.value.trim();

    if (phone === '') {
      return false;
    }

    return (
      !isLikelyValidDialCode(identityPhoneDialCode.value) ||
      !isLikelyValidLocalPhone(phone) ||
      !isLikelyValidPhone(buildPhoneNumber(identityPhoneDialCode.value, phone))
    );
  }

  if (identityForm.type === 'email') {
    const email = identityForm.value.trim();

    if (email === '') {
      return false;
    }

    return !isLikelyValidEmail(email);
  }

  return false;
});

const replaceIdentityValueErrorMessage = computed(() => {
  const errors = replaceIdentityForm.errors as Record<
    string,
    string | undefined
  >;
  const fallbackError = errors.value || errors.identity;

  if (replacingIdentity.value?.type.value === 'phone') {
    const phone = replacePhoneLocalNumber.value.trim();

    if (phone === '') {
      return fallbackError;
    }

    if (
      !isLikelyValidDialCode(replacePhoneDialCode.value) ||
      !isLikelyValidLocalPhone(phone) ||
      !isLikelyValidPhone(buildPhoneNumber(replacePhoneDialCode.value, phone))
    ) {
      return t('请输入有效的手机号');
    }

    return fallbackError;
  }

  if (replacingIdentity.value?.type.value === 'email') {
    const email = replaceIdentityForm.value.trim();

    if (email === '') {
      return fallbackError;
    }

    if (!isLikelyValidEmail(email)) {
      return t('请输入有效的邮箱地址');
    }

    return fallbackError;
  }

  return fallbackError;
});

const isReplaceIdentityValueInvalid = computed(() => {
  if (replacingIdentity.value?.type.value === 'phone') {
    const phone = replacePhoneLocalNumber.value.trim();

    if (phone === '') {
      return false;
    }

    return (
      !isLikelyValidDialCode(replacePhoneDialCode.value) ||
      !isLikelyValidLocalPhone(phone) ||
      !isLikelyValidPhone(buildPhoneNumber(replacePhoneDialCode.value, phone))
    );
  }

  if (replacingIdentity.value?.type.value === 'email') {
    const email = replaceIdentityForm.value.trim();

    if (email === '') {
      return false;
    }

    return !isLikelyValidEmail(email);
  }

  return false;
});

const identityNamespaceLabel = (namespace: string): string => {
  if (namespace === '') {
    return t('默认');
  }

  return namespace;
};

const canManageIdentity = (identity: ContactIdentityData): boolean => {
  return ['email', 'phone'].includes(String(identity.type.value));
};

const contactTypeLogLabel = (value: string | null | undefined): string => {
  if (value === 'contact') {
    return t('联系人');
  }

  if (value === 'visitor') {
    return t('访客');
  }

  return value ?? '-';
};

const activityLogTitle = (
  action: string,
  relatedContactName: string | null,
  payload?: Record<string, unknown> | null,
): string => {
  if (action === 'created') {
    return payload?.origin === 'resolve_identity'
      ? t('系统创建了联系人')
      : t('已创建联系人');
  }

  if (action === 'updated') {
    return t('已更新联系人');
  }

  if (action === 'important_marked') {
    return t('已标为重点客户');
  }

  if (action === 'important_unmarked') {
    return t('已取消重点客户');
  }

  if (action === 'identity_added') {
    return t('已添加身份标识');
  }

  if (action === 'identity_replaced') {
    return t('已替换身份标识');
  }

  if (action === 'identity_deleted') {
    return t('已删除身份标识');
  }

  if (action === 'deleted') {
    return t('已删除联系人');
  }

  if (action === 'restored') {
    return t('已恢复联系人');
  }

  if (action === 'custom_attributes_updated') {
    return t('已更新自定义属性');
  }

  if (action === 'merged_into_other') {
    return relatedContactName
      ? `${t('已合并到联系人')}「${relatedContactName}」`
      : t('已合并到其他联系人');
  }

  if (action === 'merged_into_current') {
    return relatedContactName
      ? `${t('已合并联系人')}「${relatedContactName}」`
      : t('已合并一个联系人');
  }

  if (action === 'tag_attached') {
    return t('已添加标签');
  }

  if (action === 'tag_detached') {
    return t('已移除标签');
  }

  return t('已记录一次操作');
};

const activityLogDescription = (
  action: string,
  values: string[],
  payload?: Record<string, unknown> | null,
): string | null => {
  if (action === 'created') {
    return values.length > 0
      ? `${t('初始身份标识')}: ${activityLogIdentitySummary(values)}`
      : null;
  }

  if (action === 'updated') {
    const fieldChanges = payload?.field_changes as
      | Record<string, { old?: string | null; new?: string | null }>
      | undefined;

    if (!fieldChanges) {
      return null;
    }

    const summaries = Object.entries(fieldChanges).map(([field, change]) => {
      if (field === 'name') {
        return `${t('名称')}: ${change.old ?? '-'} -> ${change.new ?? '-'}`;
      }

      if (field === 'type') {
        return `${t('类型')}: ${contactTypeLogLabel(change.old)} -> ${contactTypeLogLabel(change.new)}`;
      }

      return `${field}: ${change.old ?? '-'} -> ${change.new ?? '-'}`;
    });

    return summaries.join('；');
  }

  if (action === 'identity_added') {
    return `${t('新增身份标识')}: ${activityLogIdentitySummary(values)}`;
  }

  if (action === 'identity_replaced') {
    const oldValue =
      typeof payload?.old_value === 'string' ? payload.old_value : null;
    const newValue =
      typeof payload?.new_value === 'string' ? payload.new_value : null;

    if (oldValue || newValue) {
      return `${oldValue ?? '-'} -> ${newValue ?? '-'}`;
    }

    return `${t('替换后身份标识')}: ${activityLogIdentitySummary(values)}`;
  }

  if (action === 'identity_deleted') {
    return `${t('删除身份标识')}: ${activityLogIdentitySummary(values)}`;
  }

  if (action === 'deleted') {
    return t('此联系人已进入回收站');
  }

  if (action === 'restored') {
    return t('此联系人已从回收站恢复');
  }

  if (action === 'merged_into_other' || action === 'merged_into_current') {
    return `${t('迁移身份标识')}: ${activityLogIdentitySummary(values)}`;
  }

  if (action === 'custom_attributes_updated') {
    const changed = payload?.changed as
      | Array<{ key: string; old: unknown; new: unknown }>
      | undefined;
    if (changed && changed.length > 0) {
      return changed
        .map((c) => `${c.key}: ${c.old ?? '-'} → ${c.new ?? '-'}`)
        .join('；');
    }
    return null;
  }

  if (action === 'tag_attached' || action === 'tag_detached') {
    const tagName =
      typeof payload?.tag_name === 'string' ? payload.tag_name : null;

    return tagName ? `${t('标签')}: ${tagName}` : null;
  }

  return null;
};

const activityLogActorLabel = (
  actorName: string | null | undefined,
): string => {
  return `${t('执行人')}: ${actorName || t('系统')}`;
};

const activityLogIdentitySummary = (values: string[]): string => {
  if (values.length === 0) {
    return t('未记录身份标识');
  }

  if (values.length <= 3) {
    return values.join('、');
  }

  return `${values.slice(0, 3).join('、')} ${t('等')} ${values.length} ${t('项')}`;
};

const deleteIdentityErrorMessage = computed(() => {
  const errors = deleteIdentityForm.errors as Record<
    string,
    string | undefined
  >;

  return errors.identity;
});

const fetchDetail = async (id: string, silent = false) => {
  if (!silent) {
    loading.value = true;
  }
  detailError.value = '';
  try {
    const response = await fetch(
      workspace.contacts.show.url(
        {
          id,
        },
        {
          query: {
            include_trashed: props.includeTrashed ? 1 : undefined,
          },
        },
      ),
      {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      },
    );
    if (!response.ok) {
      throw new Error(t('联系人详情加载失败'));
    }
    contactDetail.value = await response.json();
    initAttrValues(contactDetail.value?.custom_attributes ?? []);
  } catch (error) {
    contactDetail.value = null;
    detailError.value =
      error instanceof Error ? error.message : t('联系人详情加载失败');
    throw error;
  } finally {
    if (!silent) {
      loading.value = false;
    }
  }
};

watch(attrValues, () => scheduleCustomAttributesSave(), { deep: true });

watch(
  () => props.contactId,
  (newId) => {
    if (newId) {
      fetchDetail(newId);
    }
  },
);

watch(defaultPhonePrefix, (value) => {
  if (identityPhoneLocalNumber.value.trim() !== '') {
    if (replacePhoneLocalNumber.value.trim() !== '') {
      return;
    }
  }

  if (identityPhoneLocalNumber.value.trim() === '') {
    identityPhoneDialCode.value = value;
  }

  if (replacePhoneLocalNumber.value.trim() === '') {
    replacePhoneDialCode.value = value;
  }
});

watch(
  () => identityForm.type,
  (type) => {
    if (type === 'phone') {
      identityPhoneDialCode.value = defaultPhonePrefix.value;
      identityForm.value = '';

      return;
    }

    identityPhoneDialCode.value = defaultPhonePrefix.value;
    identityPhoneLocalNumber.value = '';
    identityForm.clearErrors('value');
  },
);

watch(addIdentityOpen, (open) => {
  if (open) {
    if (identityForm.type === 'phone') {
      identityPhoneDialCode.value = defaultPhonePrefix.value;
    }

    return;
  }

  if (identityForm.processing) {
    return;
  }

  identityForm.reset();
  identityForm.type = 'email';
  identityForm.namespace = null;
  identityForm.clearErrors();
  identityPhoneDialCode.value = defaultPhonePrefix.value;
  identityPhoneLocalNumber.value = '';
});

const openReplaceIdentity = (identity: ContactIdentityData) => {
  replacingIdentity.value = identity;
  replaceIdentityForm.clearErrors();

  if (String(identity.type.value) === 'phone') {
    const parsedPhone = splitPhoneNumber(identity.display_value ?? '');
    replacePhoneDialCode.value =
      parsedPhone.dialCode || defaultPhonePrefix.value;
    replacePhoneLocalNumber.value = parsedPhone.localNumber;
    replaceIdentityForm.value = '';

    return;
  }

  replacePhoneDialCode.value = defaultPhonePrefix.value;
  replacePhoneLocalNumber.value = '';
  replaceIdentityForm.value = identity.display_value ?? '';
};

const closeReplaceIdentity = (open: boolean) => {
  if (open || replaceIdentityForm.processing) {
    return;
  }

  replacingIdentity.value = null;
  replaceIdentityForm.reset();
  replaceIdentityForm.clearErrors();
  replacePhoneDialCode.value = defaultPhonePrefix.value;
  replacePhoneLocalNumber.value = '';
};

const openDeleteIdentity = (identity: ContactIdentityData) => {
  deletingIdentity.value = identity;
  deleteIdentityForm.clearErrors();
};

const closeDeleteIdentity = (open: boolean) => {
  if (open || deleteIdentityForm.processing) {
    return;
  }

  deletingIdentity.value = null;
  deleteIdentityForm.clearErrors();
};

onMounted(() => {
  if (props.contactId) {
    fetchDetail(props.contactId);
  }
});

onUnmounted(() => {
  clearAttributeSaveTimer();
});

const openEdit = () => {
  if (!contactDetail.value) {
    return;
  }
  editForm.name = contactDetail.value.name;
  editForm.type = String(contactDetail.value.type.value);
  editForm.clearErrors();
  editOpen.value = true;
};

const submitEdit = () => {
  editForm.put(
    workspace.contacts.update.url({
      id: props.contactId,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        editOpen.value = false;
        fetchDetail(props.contactId, true);
      },
    },
  );
};

const submitAddIdentity = () => {
  if (identityForm.type === 'phone') {
    identityForm.value = buildPhoneNumber(
      identityPhoneDialCode.value,
      identityPhoneLocalNumber.value,
    );
  } else {
    identityForm.value = identityForm.value.trim();
  }

  if (isIdentityValueInvalid.value) {
    identityForm.setError(
      'value',
      identityForm.type === 'email'
        ? t('请输入有效的邮箱地址')
        : t('请输入有效的手机号'),
    );

    return;
  }

  identityForm.clearErrors('value');
  identityForm.post(
    workspace.contacts.identities.store.url({
      contactId: props.contactId,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        identityForm.reset();
        identityForm.type = 'email';
        identityForm.namespace = null;
        identityPhoneDialCode.value = defaultPhonePrefix.value;
        identityPhoneLocalNumber.value = '';
        addIdentityOpen.value = false;
        fetchDetail(props.contactId, true);
      },
    },
  );
};

const submitReplaceIdentity = () => {
  if (!replacingIdentity.value) {
    return;
  }

  if (String(replacingIdentity.value.type.value) === 'phone') {
    replaceIdentityForm.value = buildPhoneNumber(
      replacePhoneDialCode.value,
      replacePhoneLocalNumber.value,
    );
  } else {
    replaceIdentityForm.value = replaceIdentityForm.value.trim();
  }

  if (isReplaceIdentityValueInvalid.value) {
    replaceIdentityForm.setError(
      'value',
      String(replacingIdentity.value.type.value) === 'email'
        ? t('请输入有效的邮箱地址')
        : t('请输入有效的手机号'),
    );

    return;
  }

  replaceIdentityForm.clearErrors('value');
  replaceIdentityForm.put(
    workspace.contacts.identities.replace.url({
      contactId: props.contactId,
      identityId: replacingIdentity.value.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        replacingIdentity.value = null;
        replaceIdentityForm.reset();
        replacePhoneDialCode.value = defaultPhonePrefix.value;
        replacePhoneLocalNumber.value = '';
        fetchDetail(props.contactId, true);
      },
    },
  );
};

const submitDeleteIdentity = () => {
  if (!deletingIdentity.value) {
    return;
  }

  deleteIdentityForm.delete(
    workspace.contacts.identities.destroy.url({
      contactId: props.contactId,
      identityId: deletingIdentity.value.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deletingIdentity.value = null;
        fetchDetail(props.contactId, true);
      },
    },
  );
};

const nameInitial = (detail: ContactDetailData): string =>
  getAvatarInitial(detail.name);

const tagProcessing = ref(false);

const selectedTagIds = computed(() =>
  (contactDetail.value?.tags ?? []).map((t) => t.id),
);

const reloadContactList = async (): Promise<void> => {
  await new Promise<void>((resolve) => {
    router.reload({
      only: ['contact_list', 'contact_list_pagination'],
      onFinish: () => resolve(),
    });
  });
};

const toggleImportance = async (): Promise<void> => {
  if (props.readOnly || !contactDetail.value || importanceProcessing.value) {
    return;
  }

  importanceProcessing.value = true;
  try {
    await axios.put(
      workspace.contacts.importance.update.url({
        id: props.contactId,
      }),
      { is_important: !contactDetail.value.is_important },
    );

    await Promise.all([
      fetchDetail(props.contactId, true),
      reloadContactList(),
    ]);
  } finally {
    importanceProcessing.value = false;
  }
};

const handleAttachTag = async (tagId: string) => {
  if (tagProcessing.value) {
    return;
  }

  tagProcessing.value = true;
  try {
    await axios.post(
      workspace.contacts.tags.attach.url({
        id: props.contactId,
      }),
      { tag_id: tagId },
    );
    await Promise.all([
      fetchDetail(props.contactId, true),
      reloadContactList(),
    ]);
  } finally {
    tagProcessing.value = false;
  }
};

const handleDetachTag = async (tagId: string) => {
  if (tagProcessing.value) {
    return;
  }

  tagProcessing.value = true;
  try {
    await axios.delete(
      workspace.contacts.tags.detach.url({
        id: props.contactId,
        tagId,
      }),
    );
    await Promise.all([
      fetchDetail(props.contactId, true),
      reloadContactList(),
    ]);
  } finally {
    tagProcessing.value = false;
  }
};

const formatAiContext = (
  ctx: Record<string, unknown> | null,
): { key: string; value: string }[] => {
  if (!ctx) {
    return [];
  }
  return Object.entries(ctx)
    .filter(([key]) => !key.startsWith('_'))
    .map(([key, value]) => ({
      key,
      value: typeof value === 'string' ? value : JSON.stringify(value),
    }));
};

const customAttributeOptionLabel = (
  field: ContactAttributeFieldData,
  code: string,
): string => {
  const options = field.config?.options as
    | Array<{ code: string; label: string }>
    | undefined;

  return options?.find((option) => option.code === code)?.label ?? code;
};

const formatCustomAttributeValue = (
  field: ContactAttributeFieldData,
): string => {
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
};
</script>

<template>
  <div class="flex h-full min-h-0 flex-col bg-background">
    <div class="border-b p-4 pr-12">
      <h3 class="font-semibold">{{ t('联系人详情') }}</h3>
    </div>

    <div v-if="loading" class="space-y-4 p-4">
      <div class="flex items-center gap-3">
        <div class="h-12 w-12 animate-pulse rounded-full bg-muted" />
        <div class="space-y-2">
          <div class="h-4 w-24 animate-pulse rounded bg-muted" />
          <div class="h-3 w-32 animate-pulse rounded bg-muted" />
        </div>
      </div>
      <div class="h-20 animate-pulse rounded bg-muted" />
      <div class="h-20 animate-pulse rounded bg-muted" />
    </div>

    <div v-else-if="detailError" class="p-4 text-sm text-destructive">
      {{ detailError }}
    </div>

    <div v-else-if="contactDetail" class="min-h-0 flex-1 overflow-y-auto">
      <div class="space-y-4 p-4">
        <div class="flex items-start gap-3">
          <Avatar class="h-12 w-12">
            <AvatarImage :src="contactDetail.avatar_url" />
            <AvatarFallback class="text-lg">
              {{ nameInitial(contactDetail) }}
            </AvatarFallback>
          </Avatar>
          <div class="min-w-0 flex-1">
            <h4 class="truncate text-lg font-semibold">
              {{ formatVisitorName(contactDetail.name, contactDetail.id) }}
            </h4>
            <div
              class="flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground"
            >
              <Badge
                :variant="
                  String(contactDetail.type.value) === 'contact'
                    ? 'default'
                    : 'secondary'
                "
              >
                {{ contactDetail.type.label }}
              </Badge>
              <span>·</span>
              <span>{{ contactDetail.source.label }}</span>
              <span>·</span>
              <span>{{
                formatDateTime(contactDetail.created_at, 'YYYY-MM-DD')
              }}</span>
              <template v-if="contactDetail.deleted_at">
                <span>·</span>
                <span
                  >{{ t('删除于') }}
                  {{
                    formatDateTime(contactDetail.deleted_at, 'YYYY-MM-DD')
                  }}</span
                >
              </template>
            </div>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <Button
            v-if="!readOnly"
            variant="outline"
            size="sm"
            :disabled="importanceProcessing"
            :aria-pressed="contactDetail.is_important"
            @click="toggleImportance"
          >
            <Star
              class="mr-1 size-3.5"
              :class="{ 'fill-current': contactDetail.is_important }"
            />
            {{
              contactDetail.is_important ? t('取消重点客户') : t('标为重点客户')
            }}
          </Button>
          <Badge
            v-else-if="contactDetail.is_important"
            variant="outline"
            class="gap-1.5 px-2"
          >
            <Star class="size-3.5 fill-current" />
            {{ t('重点客户') }}
          </Badge>
          <Button
            v-if="readOnly && canRestore"
            variant="outline"
            size="sm"
            :disabled="restoreProcessing"
            @click="emit('requestRestore')"
          >
            {{ restoreProcessing ? t('恢复中...') : t('恢复') }}
          </Button>
          <Button
            v-if="!readOnly"
            variant="outline"
            size="sm"
            :disabled="!canMerge"
            @click="emit('requestMerge')"
          >
            {{ t('合并') }}
          </Button>
          <Dialog v-if="!readOnly" v-model:open="editOpen">
            <DialogTrigger as-child>
              <Button variant="outline" size="sm" @click="openEdit">
                {{ t('编辑') }}
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader class="space-y-3">
                <DialogTitle>{{ t('编辑联系人') }}</DialogTitle>
              </DialogHeader>
              <form class="space-y-4" @submit.prevent="submitEdit">
                <div class="space-y-2">
                  <Label for="edit-name">{{ t('名称') }}</Label>
                  <Input
                    id="edit-name"
                    :model-value="editForm.name ?? ''"
                    :disabled="editForm.processing"
                    maxlength="255"
                    @update:model-value="
                      editForm.name = ($event as string) || null
                    "
                  />
                  <InputError :message="editForm.errors.name" />
                </div>
                <div class="space-y-2">
                  <Label for="edit-type">{{ t('类型') }}</Label>
                  <Select
                    v-model="editForm.type"
                    :disabled="editForm.processing"
                  >
                    <SelectTrigger id="edit-type" class="h-9">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="visitor">{{ t('访客') }}</SelectItem>
                      <SelectItem value="contact">{{ t('联系人') }}</SelectItem>
                    </SelectContent>
                  </Select>
                  <InputError :message="editForm.errors.type" />
                </div>

                <DialogFooter class="gap-2">
                  <DialogClose as-child>
                    <Button
                      type="button"
                      variant="secondary"
                      :disabled="editForm.processing"
                    >
                      {{ t('取消') }}
                    </Button>
                  </DialogClose>
                  <Button type="submit" :disabled="editForm.processing">
                    {{ t('保存') }}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </div>

        <Separator />

        <div>
          <div class="mb-3 flex items-center justify-between">
            <h5 class="text-sm font-semibold">{{ t('身份标识') }}</h5>
            <Dialog v-if="!readOnly" v-model:open="addIdentityOpen">
              <DialogTrigger as-child>
                <Button variant="outline" size="sm">
                  {{ t('添加') }}
                </Button>
              </DialogTrigger>
              <DialogContent>
                <DialogHeader class="space-y-3">
                  <DialogTitle>{{ t('添加身份标识') }}</DialogTitle>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitAddIdentity">
                  <div class="space-y-2">
                    <Label for="identity-type">{{ t('类型') }}</Label>
                    <Select
                      v-model="identityForm.type"
                      :disabled="identityForm.processing"
                    >
                      <SelectTrigger id="identity-type" class="h-9">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem
                          v-for="opt in identityTypeOptions"
                          :key="opt.value"
                          :value="opt.value"
                        >
                          {{ opt.label }}
                        </SelectItem>
                      </SelectContent>
                    </Select>
                    <InputError :message="identityForm.errors.type" />
                  </div>
                  <div class="space-y-2">
                    <Label for="identity-value">{{ t('值') }}</Label>
                    <div
                      v-if="identityForm.type === 'phone'"
                      class="flex gap-2"
                    >
                      <PhoneDialCodeCombobox
                        v-model="identityPhoneDialCode"
                        class="w-36 shrink-0"
                        :disabled="identityForm.processing"
                      />
                      <Input
                        id="identity-value"
                        type="tel"
                        inputmode="tel"
                        :model-value="identityPhoneLocalNumber"
                        :disabled="identityForm.processing"
                        @update:model-value="
                          identityPhoneLocalNumber = $event as string
                        "
                      />
                    </div>
                    <Input
                      v-else
                      id="identity-value"
                      :type="identityForm.type === 'email' ? 'email' : 'text'"
                      :inputmode="
                        identityForm.type === 'email' ? 'email' : 'text'
                      "
                      :autocomplete="
                        identityForm.type === 'email' ? 'email' : 'off'
                      "
                      :maxlength="
                        identityForm.type === 'email'
                          ? EMAIL_MAX_LENGTH
                          : undefined
                      "
                      v-model="identityForm.value"
                      :disabled="identityForm.processing"
                    />
                    <InputError :message="identityValueErrorMessage" />
                  </div>

                  <DialogFooter class="gap-2">
                    <DialogClose as-child>
                      <Button
                        type="button"
                        variant="secondary"
                        :disabled="identityForm.processing"
                      >
                        {{ t('取消') }}
                      </Button>
                    </DialogClose>
                    <Button
                      type="submit"
                      :disabled="
                        identityForm.processing || isIdentityValueInvalid
                      "
                    >
                      {{ t('保存') }}
                    </Button>
                  </DialogFooter>
                </form>
              </DialogContent>
            </Dialog>
          </div>

          <div class="space-y-2">
            <div
              v-for="identity in contactDetail.identities"
              :key="identity.id"
              class="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
            >
              <div class="flex items-center gap-2">
                <Badge
                  class="border-transparent bg-muted text-muted-foreground hover:bg-muted/80"
                >
                  {{ identity.type.label }}
                </Badge>
                <div class="flex flex-col gap-1">
                  <span class="text-muted-foreground">
                    {{ identity.display_value || '-' }}
                  </span>
                  <span
                    v-if="identity.namespace"
                    class="text-xs text-muted-foreground"
                  >
                    {{ t('命名空间') }}:
                    {{ identityNamespaceLabel(identity.namespace) }}
                  </span>
                </div>
              </div>
              <div
                v-if="!readOnly && canManageIdentity(identity)"
                class="flex items-center gap-2"
              >
                <Button
                  variant="ghost"
                  size="sm"
                  class="h-8 px-2 text-muted-foreground"
                  @click="openReplaceIdentity(identity)"
                >
                  {{ t('替换') }}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  class="h-8 px-2 text-destructive hover:text-destructive"
                  @click="openDeleteIdentity(identity)"
                >
                  {{ t('删除') }}
                </Button>
              </div>
            </div>
            <div
              v-if="contactDetail.identities.length === 0"
              class="py-4 text-center text-sm text-muted-foreground"
            >
              {{ t('暂无身份标识') }}
            </div>
          </div>
        </div>

        <Dialog
          :open="replacingIdentity !== null"
          @update:open="closeReplaceIdentity"
        >
          <DialogContent>
            <DialogHeader class="space-y-3">
              <DialogTitle>{{ t('替换身份标识') }}</DialogTitle>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submitReplaceIdentity">
              <div class="space-y-2">
                <Label>{{ t('当前值') }}</Label>
                <div
                  class="rounded-md bg-muted/30 px-3 py-2 text-sm text-muted-foreground"
                >
                  {{ replacingIdentity?.display_value || '-' }}
                </div>
              </div>
              <div class="space-y-2">
                <Label for="replace-identity-value">{{ t('新值') }}</Label>
                <div
                  v-if="replacingIdentity?.type.value === 'phone'"
                  class="flex gap-2"
                >
                  <PhoneDialCodeCombobox
                    v-model="replacePhoneDialCode"
                    class="w-36 shrink-0"
                    :disabled="replaceIdentityForm.processing"
                  />
                  <Input
                    id="replace-identity-value"
                    type="tel"
                    inputmode="tel"
                    :model-value="replacePhoneLocalNumber"
                    :disabled="replaceIdentityForm.processing"
                    @update:model-value="
                      replacePhoneLocalNumber = $event as string
                    "
                  />
                </div>
                <Input
                  v-else
                  id="replace-identity-value"
                  :type="
                    replacingIdentity?.type.value === 'email' ? 'email' : 'text'
                  "
                  :inputmode="
                    replacingIdentity?.type.value === 'email' ? 'email' : 'text'
                  "
                  :autocomplete="
                    replacingIdentity?.type.value === 'email' ? 'email' : 'off'
                  "
                  :maxlength="
                    replacingIdentity?.type.value === 'email'
                      ? EMAIL_MAX_LENGTH
                      : undefined
                  "
                  v-model="replaceIdentityForm.value"
                  :disabled="replaceIdentityForm.processing"
                />
                <InputError :message="replaceIdentityValueErrorMessage" />
              </div>

              <DialogFooter class="gap-2">
                <DialogClose as-child>
                  <Button
                    type="button"
                    variant="secondary"
                    :disabled="replaceIdentityForm.processing"
                  >
                    {{ t('取消') }}
                  </Button>
                </DialogClose>
                <Button
                  type="submit"
                  :disabled="
                    replaceIdentityForm.processing ||
                    isReplaceIdentityValueInvalid
                  "
                >
                  {{ t('保存') }}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        <Dialog
          :open="deletingIdentity !== null"
          @update:open="closeDeleteIdentity"
        >
          <DialogContent>
            <DialogHeader class="space-y-3">
              <DialogTitle>{{ t('确认删除身份标识？') }}</DialogTitle>
            </DialogHeader>
            <div
              class="rounded-md bg-muted/30 px-3 py-3 text-sm text-muted-foreground"
            >
              {{ deletingIdentity?.display_value || '-' }}
            </div>
            <InputError :message="deleteIdentityErrorMessage" />
            <DialogFooter class="gap-2">
              <DialogClose as-child>
                <Button
                  variant="secondary"
                  :disabled="deleteIdentityForm.processing"
                >
                  {{ t('取消') }}
                </Button>
              </DialogClose>
              <Button
                variant="destructive"
                :disabled="deleteIdentityForm.processing"
                @click="submitDeleteIdentity"
              >
                {{
                  deleteIdentityForm.processing ? t('删除中...') : t('确认删除')
                }}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        <div v-if="!readOnly && availableTags && availableTags.length > 0">
          <Separator class="mb-4" />
          <h5 class="mb-3 text-sm font-semibold">{{ t('标签') }}</h5>
          <TagSelector
            :options="availableTags"
            :selected-tag-ids="selectedTagIds"
            :disabled="tagProcessing"
            @attach="handleAttachTag"
            @detach="handleDetachTag"
          />
          <div
            v-if="selectedTagIds.length === 0"
            class="mt-2 text-sm text-muted-foreground"
          >
            {{ t('暂无标签') }}
          </div>
        </div>

        <div
          v-else-if="
            readOnly && contactDetail.tags && contactDetail.tags.length > 0
          "
        >
          <Separator class="mb-4" />
          <h5 class="mb-3 text-sm font-semibold">{{ t('标签') }}</h5>
          <div class="flex flex-wrap gap-1.5">
            <Badge
              v-for="tag in contactDetail.tags"
              :key="tag.id"
              class="flex items-center gap-1.5 border bg-background text-foreground shadow-sm"
            >
              <span
                class="h-2 w-2 shrink-0 rounded-full"
                :style="{ backgroundColor: tag.color ?? '#94a3b8' }"
              />
              {{ tag.name }}
            </Badge>
          </div>
        </div>

        <div v-if="contactDetail.ai_context">
          <h5 class="mb-3 text-sm font-semibold">{{ t('AI 画像') }}</h5>
          <div class="space-y-2">
            <div
              v-for="item in formatAiContext(contactDetail.ai_context)"
              :key="item.key"
              class="rounded-md border px-3 py-2 text-sm"
            >
              <span class="font-medium text-foreground">{{ item.key }}</span>
              <span class="ml-2 text-muted-foreground">{{ item.value }}</span>
            </div>
            <div
              v-if="formatAiContext(contactDetail.ai_context).length === 0"
              class="py-4 text-center text-sm text-muted-foreground"
            >
              {{ t('暂无 AI 画像数据') }}
            </div>
          </div>
        </div>

        <div
          v-if="
            contactDetail.locale ||
            contactDetail.timezone ||
            contactDetail.country ||
            contactDetail.city
          "
        >
          <Separator class="mb-4" />
          <h5 class="mb-3 text-sm font-semibold">{{ t('其他信息') }}</h5>
          <div class="space-y-1 text-sm">
            <div v-if="contactDetail.locale" class="flex justify-between">
              <span class="text-muted-foreground">{{ t('语言') }}</span>
              <span>{{ contactDetail.locale }}</span>
            </div>
            <div v-if="contactDetail.timezone" class="flex justify-between">
              <span class="text-muted-foreground">{{ t('时区') }}</span>
              <span>{{ contactDetail.timezone }}</span>
            </div>
            <div v-if="contactDetail.country" class="flex justify-between">
              <span class="text-muted-foreground">{{ t('国家') }}</span>
              <span>{{ contactDetail.country }}</span>
            </div>
            <div v-if="contactDetail.city" class="flex justify-between">
              <span class="text-muted-foreground">{{ t('城市') }}</span>
              <span>{{ contactDetail.city }}</span>
            </div>
          </div>
        </div>

        <template v-if="hasCustomAttributes">
          <Separator />
          <div>
            <div class="mb-3 flex items-center justify-between">
              <h5 class="text-sm font-semibold">{{ t('自定义属性') }}</h5>
            </div>

            <div v-if="editableAttributes.length > 0" class="space-y-3">
              <AttributeFieldRenderer
                v-for="field in editableAttributes"
                :key="field.definition_id"
                :field="field"
                :model-value="attrValues[field.key]"
                :errors="attrFieldError(field.key)"
                :disabled="readOnly || attrSaving"
                @update:model-value="attrValues[field.key] = $event"
              />
              <div
                v-if="attrSaving"
                class="text-right text-xs text-muted-foreground"
              >
                {{ t('保存中...') }}
              </div>
            </div>

            <div
              v-if="deletedAttributes.length > 0"
              :class="{ 'mt-4': editableAttributes.length > 0 }"
            >
              <div
                v-for="field in deletedAttributes"
                :key="field.definition_id"
                class="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
              >
                <div class="space-y-1">
                  <div class="flex items-center gap-2">
                    <span class="text-muted-foreground">{{ field.name }}</span>
                    <Badge variant="outline" class="text-muted-foreground">{{
                      t('已删除')
                    }}</Badge>
                    <Badge v-if="field.source_label" variant="secondary">
                      {{ field.source_label }}
                    </Badge>
                  </div>
                  <p
                    v-if="field.description"
                    class="text-xs text-muted-foreground"
                  >
                    {{ field.description }}
                  </p>
                </div>
                <span
                  class="max-w-56 truncate text-right"
                  :title="formatCustomAttributeValue(field)"
                >
                  {{ formatCustomAttributeValue(field) }}
                </span>
              </div>
            </div>

            <div
              v-if="
                editableAttributes.length === 0 &&
                deletedAttributes.length === 0
              "
              class="py-4 text-center text-sm text-muted-foreground"
            >
              {{ t('暂无自定义属性') }}
            </div>
          </div>
        </template>

        <Separator />

        <div>
          <h5 class="mb-3 text-sm font-semibold">{{ t('操作记录') }}</h5>
          <div class="space-y-2">
            <div
              v-for="activityLog in contactDetail.activity_logs"
              :key="activityLog.id"
              class="rounded-md border px-3 py-3 text-sm"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="space-y-1">
                  <div class="font-medium text-foreground">
                    {{
                      activityLogTitle(
                        activityLog.action,
                        activityLog.related_contact_name,
                        activityLog.payload,
                      )
                    }}
                  </div>
                  <div
                    v-if="
                      activityLogDescription(
                        activityLog.action,
                        activityLog.identity_values,
                        activityLog.payload,
                      )
                    "
                    class="text-muted-foreground"
                  >
                    {{
                      activityLogDescription(
                        activityLog.action,
                        activityLog.identity_values,
                        activityLog.payload,
                      )
                    }}
                  </div>
                  <div class="text-xs text-muted-foreground">
                    {{ activityLogActorLabel(activityLog.actor_name) }}
                  </div>
                </div>
                <div class="shrink-0 text-xs text-muted-foreground">
                  {{ formatDateTime(activityLog.created_at) }}
                </div>
              </div>
            </div>
            <div
              v-if="contactDetail.activity_logs.length === 0"
              class="py-4 text-center text-sm text-muted-foreground"
            >
              {{ t('暂无操作记录') }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
