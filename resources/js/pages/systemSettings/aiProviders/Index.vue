<!--
  文件说明：AI 供应商页面，承接系统级供应商凭据与模型管理。
  消费 ShowAiProviderPagePropsData（providers + brandOptions）。供应商存在即可用，无启用开关；删除供应商即停用。
-->
<script setup lang="ts">
import AiProvider from '@/actions/App/Actions/AiProvider';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import InputError from '@/components/common/InputError.vue';
import AiProviderIcon from '@/components/icons/AiProviderIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Switch } from '@/components/ui/switch';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type {
  AiModelData,
  ShowAiProviderPagePropsData,
} from '@/types/generated';
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import {
  ChevronDown,
  ChevronRight,
  LoaderCircle,
  Pencil,
  Plus,
  Trash2,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import AddProviderDialog from './AddProviderDialog.vue';
import ModelFormDialog from './ModelFormDialog.vue';

type CredentialFieldOption = {
  value: string;
  label: string;
};

type CredentialField = {
  field: string;
  label: string;
  type?: 'text' | 'password' | 'url' | 'select';
  required?: boolean;
  secret?: boolean;
  default?: string | null;
  placeholder?: string | null;
  options?: CredentialFieldOption[];
};

type CredentialFormData = {
  configuration: Record<string, string>;
};

type InteractionIssue = {
  message: string;
  section: 'config' | 'models';
};

type DeleteTarget =
  | {
      type: 'provider';
      slug: string;
      title: string;
      subtitle?: string;
    }
  | {
      type: 'model';
      id: string;
      title: string;
      subtitle?: string;
    };

const props = defineProps<ShowAiProviderPagePropsData>();
const { t } = useI18n();
const { toast } = useToast();
const selectedProviderQueryParam = 'provider';

const providerExists = (slug: string | null): slug is string =>
  slug !== null && props.providers.some((provider) => provider.slug === slug);

const defaultProviderSlug = (): string | null =>
  props.providers[0]?.slug ?? null;

const readSelectedProviderFromUrl = (): string | null => {
  if (typeof window === 'undefined') {
    return defaultProviderSlug();
  }

  const requestedProvider = new URLSearchParams(window.location.search).get(
    selectedProviderQueryParam,
  );

  return providerExists(requestedProvider)
    ? requestedProvider
    : defaultProviderSlug();
};

const writeSelectedProviderToUrl = (slug: string | null): void => {
  if (typeof window === 'undefined') {
    return;
  }

  const url = new URL(window.location.href);

  if (slug === null || slug === defaultProviderSlug()) {
    url.searchParams.delete(selectedProviderQueryParam);
  } else {
    url.searchParams.set(selectedProviderQueryParam, slug);
  }

  window.history.replaceState(window.history.state, '', url.toString());
};

const selectedSlug = ref<string | null>(readSelectedProviderFromUrl());
const isChecking = ref(false);
const addProviderOpen = ref(false);
const deleteTarget = ref<DeleteTarget | null>(null);
const isDeleting = ref(false);
const credentialSavingField = ref<string | null>(null);

const selectedProvider = computed(
  () =>
    props.providers.find((provider) => provider.slug === selectedSlug.value) ??
    null,
);

const deleteDialogTitle = computed(() => {
  if (deleteTarget.value?.type === 'provider') {
    return t('确认删除供应商？');
  }

  if (deleteTarget.value?.type === 'model') {
    return t('确认删除模型？');
  }

  return '';
});

const deleteDialogDescription = computed(() => {
  if (deleteTarget.value?.type === 'provider') {
    return t('确定要删除该供应商吗？所有关联模型都会一起删除。');
  }

  if (deleteTarget.value?.type === 'model') {
    return t('确定要删除这个模型吗？删除后无法恢复。');
  }

  return '';
});

const selectedCredentialFields = computed<CredentialField[]>(() => {
  if (!selectedProvider.value) {
    return [];
  }

  return selectedProvider.value.credential_fields as CredentialField[];
});

const isLockedEndpointField = (field: CredentialField): boolean =>
  field.field === 'base_uri';

const editableCredentialFields = computed<CredentialField[]>(() =>
  selectedCredentialFields.value.filter(
    (field) => !isLockedEndpointField(field),
  ),
);

const lockedEndpointFields = computed<CredentialField[]>(() =>
  selectedCredentialFields.value.filter((field) =>
    isLockedEndpointField(field),
  ),
);

watch(
  () => props.providers,
  (providers) => {
    if (
      selectedSlug.value &&
      !providers.find((provider) => provider.slug === selectedSlug.value)
    ) {
      selectedSlug.value = providers[0]?.slug ?? null;
      return;
    }

    writeSelectedProviderToUrl(selectedSlug.value);
  },
);

watch(selectedSlug, (slug) => {
  writeSelectedProviderToUrl(slug);
});

onMounted(() => {
  writeSelectedProviderToUrl(selectedSlug.value);
});

const modelGroups = computed(() => {
  const provider = selectedProvider.value;
  if (!provider) {
    return [];
  }

  const groups: Record<string, AiModelData[]> = {};

  for (const model of provider.models) {
    if (!groups[model.type]) {
      groups[model.type] = [];
    }

    groups[model.type].push(model);
  }

  return Object.entries(groups).map(([type, models]) => ({ type, models }));
});

const activeLlmModelCount = computed(() => {
  const provider = selectedProvider.value;
  if (!provider) {
    return 0;
  }

  return provider.models.filter(
    (model) => model.type === 'llm' && model.is_active,
  ).length;
});

const expandedGroups = ref<Set<string>>(
  new Set(['llm', 'embedding', 'rerank']),
);

const credentialForm = useForm<CredentialFormData>({
  configuration: {},
});

const buildConfiguration = (
  provider: (typeof props.providers)[number] | null,
): Record<string, string> => {
  if (!provider) {
    return {};
  }

  const configuration: Record<string, string> = {};

  for (const rawField of provider.credential_fields as CredentialField[]) {
    if (!rawField?.field) {
      continue;
    }

    if (isLockedEndpointField(rawField)) {
      continue;
    }

    if (rawField.secret) {
      configuration[rawField.field] = '';
      continue;
    }

    const value = provider.credential_values[rawField.field];
    configuration[rawField.field] =
      value ?? (rawField.default as string | null) ?? '';
  }

  return configuration;
};

watch(
  selectedProvider,
  (provider) => {
    credentialForm.defaults({
      configuration: buildConfiguration(provider),
    });
    credentialForm.reset();
    credentialForm.clearErrors();
  },
  { immediate: true },
);

function credentialMask(fieldName: string): string | null {
  const value = selectedProvider.value?.credential_masks[fieldName];

  return typeof value === 'string' && value.length > 0 ? value : null;
}

function credentialFieldPlaceholder(field: CredentialField): string {
  if (field.secret && credentialMask(field.field)) {
    return t('已配置，输入新值后点击更新');
  }

  return field.placeholder ?? '';
}

function endpointFieldValue(field: CredentialField): string {
  const value = selectedProvider.value?.credential_values[field.field];

  return value ?? (field.default as string | null) ?? '';
}

function editableConfigurationPayload(): Record<string, string> {
  const payload: Record<string, string> = {};

  for (const field of editableCredentialFields.value) {
    const value = credentialForm.configuration[field.field] ?? '';

    if (field.secret && value === '' && credentialMask(field.field)) {
      continue;
    }

    payload[field.field] = value;
  }

  return payload;
}

const toggleGroup = (type: string) => {
  if (expandedGroups.value.has(type)) {
    expandedGroups.value.delete(type);

    return;
  }

  expandedGroups.value.add(type);
};

const setConfigurationValue = (fieldName: string, value: unknown): void => {
  credentialForm.configuration[fieldName] =
    typeof value === 'string' || typeof value === 'number' ? String(value) : '';
};

const fieldValue = (fieldName: string): string =>
  credentialForm.configuration[fieldName] ?? '';

const fieldError = (fieldName: string): string | undefined =>
  credentialForm.errors[`configuration.${fieldName}`];

const explainIssue = async (issue: InteractionIssue) => {
  toast.warning(issue.message);
};

const firstErrorMessage = (errors: Record<string, string | undefined>) => {
  return Object.values(errors).find(
    (message): message is string =>
      typeof message === 'string' && message.trim().length > 0,
  );
};

const handleActionError = async (
  errors: Record<string, string | undefined>,
) => {
  if (typeof errors.toast === 'string' && errors.toast.trim().length > 0) {
    return;
  }

  const message = firstErrorMessage(errors);
  if (!message) {
    return;
  }

  toast.warning(message);
};

function credentialFieldPayload(
  field: CredentialField,
): Record<string, string> {
  const value = credentialForm.configuration[field.field] ?? '';

  if (field.secret && value === '' && credentialMask(field.field)) {
    return {};
  }

  return {
    [field.field]: value,
  };
}

function canUpdateCredentialField(field: CredentialField): boolean {
  if (!selectedSlug.value || credentialForm.processing) {
    return false;
  }

  if (!field.secret) {
    return true;
  }

  return fieldValue(field.field).trim().length > 0;
}

const saveCredentialField = (field: CredentialField) => {
  if (!selectedSlug.value || !canUpdateCredentialField(field)) {
    return;
  }

  credentialSavingField.value = field.field;
  credentialForm.clearErrors();
  credentialForm
    .transform(() => ({
      configuration: credentialFieldPayload(field),
    }))
    .put(
      AiProvider.UpdateAiProviderCredentialsAction.url({
        provider: selectedSlug.value,
      }),
      {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          if (field.secret) {
            credentialForm.configuration[field.field] = '';
          }
        },
        onError: (errors) => {
          handleActionError(errors as Record<string, string | undefined>);
        },
        onFinish: () => {
          credentialSavingField.value = null;
        },
      },
    );
};

const openDeleteProviderDialog = (
  provider: (typeof props.providers)[number],
) => {
  deleteTarget.value = {
    type: 'provider',
    slug: provider.slug,
    title: provider.name,
  };
};

const deleteProvider = (slug: string) => {
  isDeleting.value = true;

  router.delete(
    AiProvider.DeleteAiProviderAction.url({
      provider: slug,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deleteTarget.value = null;
      },
      onFinish: () => {
        isDeleting.value = false;
      },
      onError: (errors) =>
        handleActionError(errors as Record<string, string | undefined>),
    },
  );
};

const closeDeleteDialog = (open: boolean) => {
  if (open || isDeleting.value) {
    return;
  }

  deleteTarget.value = null;
};

const checkConnection = async () => {
  if (!selectedSlug.value) {
    return;
  }

  isChecking.value = true;

  try {
    const { data } = await axios.post(
      AiProvider.CheckAiProviderAction.url({
        provider: selectedSlug.value,
      }),
      {
        configuration: editableConfigurationPayload(),
      },
    );

    const message =
      typeof data?.message === 'string' && data.message.length > 0
        ? data.message
        : '';

    if (data?.success) {
      toast.success(message || t('连接测试成功'));
    } else {
      toast.error(message || t('连接测试失败'));
    }
  } catch {
    // 网络/5xx 等异常由全局 axios interceptor 统一 toast，这里不再重复。
  } finally {
    isChecking.value = false;
  }
};

const modelDialogOpen = ref(false);
const editingModel = ref<AiModelData | null>(null);

const openAddModel = () => {
  editingModel.value = null;
  modelDialogOpen.value = true;
};

const openEditModel = (model: AiModelData) => {
  editingModel.value = model;
  modelDialogOpen.value = true;
};

const modelToggleIssue = (model: AiModelData): InteractionIssue | null => {
  if (model.type !== 'llm' || !model.is_active) {
    return null;
  }

  if (activeLlmModelCount.value > 1) {
    return null;
  }

  return {
    message: t('至少保留一个启用中的大语言模型'),
    section: 'models',
  };
};

const toggleModel = async (model: AiModelData) => {
  if (!selectedSlug.value) {
    return;
  }

  const issue = modelToggleIssue(model);
  if (issue) {
    await explainIssue(issue);
    return;
  }

  router.put(
    AiProvider.ToggleAiModelAction.url({
      provider: selectedSlug.value,
      model: model.id,
    }),
    {},
    {
      preserveScroll: true,
      onError: (errors) =>
        handleActionError(errors as Record<string, string | undefined>),
    },
  );
};

const openDeleteModelDialog = (model: AiModelData) => {
  deleteTarget.value = {
    type: 'model',
    id: model.id,
    title: model.name,
    subtitle: model.model_id,
  };
};

const deleteModel = (modelId: string) => {
  if (!selectedSlug.value) {
    return;
  }

  isDeleting.value = true;

  router.delete(
    AiProvider.DeleteAiModelAction.url({
      provider: selectedSlug.value,
      model: modelId,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deleteTarget.value = null;
      },
      onFinish: () => {
        isDeleting.value = false;
      },
      onError: (errors) =>
        handleActionError(errors as Record<string, string | undefined>),
    },
  );
};

const confirmDelete = () => {
  if (!deleteTarget.value || isDeleting.value) {
    return;
  }

  if (deleteTarget.value.type === 'provider') {
    deleteProvider(deleteTarget.value.slug);

    return;
  }

  deleteModel(deleteTarget.value.id);
};

const modelTypeLabel = (type: string) => {
  const labels: Record<string, string> = {
    llm: t('大语言模型'),
    embedding: t('嵌入模型'),
    rerank: t('重排序模型'),
  };

  return labels[type] ?? type;
};

const providerInitial = (name: string) => name.charAt(0).toUpperCase();
</script>

<template>
  <AppLayout>
    <Head :title="t('大模型供应商')" />

    <SystemSettingsLayout>
      <section class="mx-auto w-full max-w-none space-y-12">
        <div
          class="flex h-[calc(100svh-7rem)] flex-col space-y-6 overflow-hidden md:h-[calc(100svh-4rem)]"
        >
          <HeadingSmall
            :title="t('大模型供应商')"
            :description="t('管理系统级大模型供应商凭据与可用模型。')"
          />

          <div class="flex min-h-0 flex-1 rounded-xl border">
            <div class="flex w-64 shrink-0 flex-col border-r">
              <div class="flex items-center justify-between p-4">
                <h3 class="text-sm font-semibold">{{ t('供应商') }}</h3>
                <Button
                  variant="ghost"
                  size="icon"
                  class="h-7 w-7"
                  @click="addProviderOpen = true"
                >
                  <Plus class="h-4 w-4" />
                </Button>
              </div>

              <div class="flex-1 overflow-y-auto px-2 pb-4">
                <div class="space-y-0.5">
                  <button
                    v-for="provider in props.providers"
                    :key="provider.slug"
                    type="button"
                    class="flex w-full min-w-0 items-center gap-3 rounded-md py-2 pr-3 pl-3 text-left text-sm transition-colors"
                    :class="
                      selectedSlug === provider.slug
                        ? 'bg-accent text-accent-foreground'
                        : 'hover:bg-muted'
                    "
                    @click="selectedSlug = provider.slug"
                  >
                    <AiProviderIcon
                      :icon="provider.icon"
                      class="h-7 w-7 shrink-0 rounded-md bg-muted p-1.5"
                    >
                      {{ providerInitial(provider.name) }}
                    </AiProviderIcon>
                    <span class="min-w-0 flex-1 truncate font-medium">
                      {{ provider.name }}
                    </span>
                  </button>
                </div>

                <p
                  v-if="props.providers.length === 0"
                  class="px-3 py-8 text-center text-xs text-muted-foreground"
                >
                  {{ t('点击右上角 + 添加供应商') }}
                </p>
              </div>
            </div>

            <div class="flex-1 overflow-y-auto">
              <template v-if="selectedProvider">
                <div class="space-y-6 p-6">
                  <form class="space-y-4" @submit.prevent>
                    <div class="flex items-center justify-between gap-3">
                      <h3 class="text-sm font-semibold">
                        {{ t('连接与凭据') }}
                      </h3>
                      <div class="flex shrink-0 items-center gap-2">
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          :disabled="isChecking"
                          @click="checkConnection"
                        >
                          <LoaderCircle
                            v-if="isChecking"
                            class="mr-2 h-4 w-4 animate-spin"
                          />
                          {{ t('测试') }}
                        </Button>

                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          class="text-destructive hover:text-destructive"
                          :title="t('删除')"
                          @click="openDeleteProviderDialog(selectedProvider)"
                        >
                          <Trash2 class="h-4 w-4" />
                        </Button>
                      </div>
                    </div>

                    <div
                      v-for="field in editableCredentialFields"
                      :key="field.field"
                      class="grid gap-2"
                    >
                      <Label :for="field.field" :required="field.required">
                        {{ field.label }}
                      </Label>

                      <div class="flex items-center gap-2">
                        <div class="min-w-0 flex-1">
                          <Select
                            v-if="field.type === 'select'"
                            :model-value="fieldValue(field.field)"
                            @update:model-value="
                              (value) =>
                                setConfigurationValue(field.field, value)
                            "
                          >
                            <SelectTrigger :id="field.field" class="w-full">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem
                                v-for="option in field.options ?? []"
                                :key="option.value"
                                :value="option.value"
                              >
                                {{ option.label }}
                              </SelectItem>
                            </SelectContent>
                          </Select>

                          <Input
                            v-else
                            :id="field.field"
                            :model-value="fieldValue(field.field)"
                            :type="
                              field.secret
                                ? 'password'
                                : field.type === 'url'
                                  ? 'url'
                                  : 'text'
                            "
                            :placeholder="credentialFieldPlaceholder(field)"
                            autocomplete="off"
                            class="w-full"
                            @update:model-value="
                              (value) =>
                                setConfigurationValue(field.field, value)
                            "
                          />
                        </div>

                        <Button
                          type="button"
                          variant="outline"
                          :disabled="!canUpdateCredentialField(field)"
                          @click="saveCredentialField(field)"
                        >
                          <LoaderCircle
                            v-if="
                              credentialForm.processing &&
                              credentialSavingField === field.field
                            "
                            class="mr-2 h-4 w-4 animate-spin"
                          />
                          {{ t('更新') }}
                        </Button>
                      </div>

                      <InputError :message="fieldError(field.field)" />
                    </div>

                    <div
                      v-for="field in lockedEndpointFields"
                      :key="`locked-${field.field}`"
                      class="grid gap-2"
                    >
                      <Label :for="field.field">
                        {{ field.label }}
                      </Label>
                      <Input
                        :id="field.field"
                        :model-value="endpointFieldValue(field)"
                        type="url"
                        disabled
                        class="w-full"
                      />
                    </div>
                  </form>

                  <Separator />

                  <div class="space-y-4">
                    <div class="flex items-center justify-between">
                      <h3 class="text-sm font-semibold">{{ t('模型') }}</h3>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="openAddModel"
                      >
                        {{ t('添加模型') }}
                      </Button>
                    </div>

                    <div v-if="modelGroups.length > 0" class="space-y-2">
                      <div
                        v-for="group in modelGroups"
                        :key="group.type"
                        class="rounded-lg border"
                      >
                        <button
                          class="flex w-full items-center justify-between px-4 py-3 text-left text-sm font-medium hover:bg-muted/50"
                          @click="toggleGroup(group.type)"
                        >
                          <span
                            >{{ modelTypeLabel(group.type) }} ({{
                              group.models.length
                            }})</span
                          >
                          <ChevronDown
                            v-if="expandedGroups.has(group.type)"
                            class="h-4 w-4 text-muted-foreground"
                          />
                          <ChevronRight
                            v-else
                            class="h-4 w-4 text-muted-foreground"
                          />
                        </button>

                        <div
                          v-if="expandedGroups.has(group.type)"
                          class="border-t"
                        >
                          <div
                            v-for="model in group.models"
                            :key="model.id"
                            class="flex items-center justify-between px-4 py-2.5 hover:bg-muted/30"
                          >
                            <div class="min-w-0 flex-1">
                              <div class="flex items-center gap-2">
                                <span class="text-sm font-medium">{{
                                  model.name
                                }}</span>
                                <Badge
                                  v-if="model.is_builtin"
                                  variant="outline"
                                  class="text-[10px]"
                                >
                                  {{ t('内置') }}
                                </Badge>
                              </div>

                              <div class="mt-0.5 flex items-center gap-1.5">
                                <span
                                  class="font-mono text-xs text-muted-foreground"
                                >
                                  {{ model.model_id }}
                                </span>
                              </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                              <Switch
                                :model-value="model.is_active"
                                :title="model.is_active ? t('停用') : t('启用')"
                                @update:model-value="() => toggleModel(model)"
                              />
                              <Button
                                variant="ghost"
                                size="icon"
                                class="h-7 w-7"
                                @click="openEditModel(model)"
                              >
                                <Pencil class="h-3.5 w-3.5" />
                              </Button>
                              <Button
                                v-if="!model.is_builtin"
                                variant="ghost"
                                size="icon"
                                class="h-7 w-7 text-destructive hover:text-destructive"
                                :title="t('删除')"
                                @click="openDeleteModelDialog(model)"
                              >
                                <Trash2 class="h-3.5 w-3.5" />
                              </Button>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <p
                      v-else
                      class="py-8 text-center text-sm text-muted-foreground"
                    >
                      {{ t('暂无模型') }}
                    </p>
                  </div>
                </div>
              </template>

              <div
                v-else
                class="flex h-full items-center justify-center text-sm text-muted-foreground"
              >
                {{ t('暂无供应商') }}
              </div>
            </div>
          </div>

          <AddProviderDialog
            v-model:open="addProviderOpen"
            :brand-options="props.brand_options"
          />

          <ModelFormDialog
            v-model:open="modelDialogOpen"
            :model="editingModel"
            :provider-name="selectedProvider?.name ?? ''"
            :provider-slug="selectedProvider?.slug ?? ''"
          />

          <ConfirmDeleteDialog
            :open="deleteTarget !== null"
            :title="deleteDialogTitle"
            :detail-title="deleteTarget?.title"
            :detail-description="deleteDialogDescription"
            :processing="isDeleting"
            @update:open="closeDeleteDialog"
            @confirm="confirmDelete"
          />
        </div>
      </section>
    </SystemSettingsLayout>
  </AppLayout>
</template>
