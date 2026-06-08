<!--
  系统 AI 供应商创建/编辑表单面板，供创建页和编辑页复用。
  承接品牌选择、动态凭据录入与连通测试（仅编辑页）；提交到 admin.manage.ai.providers.* 路由。
-->
<script setup lang="ts">
import AiProvider from '@/actions/App/Actions/AiProvider';
import FormActions from '@/components/common/FormActions.vue';
import FormField from '@/components/common/FormField.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { AiProviderData, BrandOptionData } from '@/types/generated';
import { useForm, useHttp } from '@inertiajs/vue3';
import { LoaderCircle, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';

type CredentialField = {
  field: string;
  label: string;
  type?: 'text' | 'password' | 'url';
  required?: boolean;
  secret?: boolean;
  default?: string | null;
};

type ProviderForm = {
  name: string;
  brand: string;
  configuration: Record<string, string>;
};

type CheckPayload = { configuration: Record<string, string> };
type CheckResponse = { success: boolean; message: string };

const props = defineProps<{
  mode: 'create' | 'edit';
  provider?: AiProviderData | null;
  brandOptions?: BrandOptionData[];
  returnHref: string;
}>();

const emit = defineEmits<{
  saved: [];
  clearCredentials: [];
}>();

const { t } = useI18n();
const { toast } = useToast();

const isEditMode = computed(() => props.mode === 'edit');
const brandOptions = computed(() => props.brandOptions ?? []);

const defaultBrand = computed(() => brandOptions.value[0]?.brand ?? '');

const selectedBrand = computed<string>(() =>
  isEditMode.value ? (props.provider?.brand ?? '') : form.brand,
);

const selectedBrandOption = computed(() =>
  brandOptions.value.find((option) => option.brand === selectedBrand.value),
);

const selectedBrandLabel = computed(() =>
  isEditMode.value
    ? (props.provider?.brand_label ?? '')
    : (selectedBrandOption.value?.label ?? ''),
);

// 预设品牌（非自定义）的 Base URL 已内置，凭据里的 url 字段只读、不允许修改。
const isCustomBrand = computed<boolean>(() =>
  isEditMode.value
    ? (props.provider?.is_custom ?? false)
    : (selectedBrandOption.value?.is_custom ?? false),
);

const title = computed(() =>
  isEditMode.value ? t('编辑 AI 供应商') : t('新增 AI 供应商'),
);

const description = computed(() =>
  isEditMode.value
    ? t('调整 AI 供应商的名称与凭据。')
    : t('选择品牌并填写凭据。'),
);

const submitLabel = computed(() => (isEditMode.value ? t('保存') : t('创建')));

function isReadonlyField(field: CredentialField): boolean {
  return field.type === 'url' && !isCustomBrand.value;
}

function credentialFieldsForBrand(brand: string): CredentialField[] {
  return (brandOptions.value.find((option) => option.brand === brand)
    ?.credential_fields ?? []) as CredentialField[];
}

const selectedCredentialFields = computed<CredentialField[]>(() => {
  if (isEditMode.value && props.provider) {
    return props.provider.credential_fields as CredentialField[];
  }

  return credentialFieldsForBrand(form.brand);
});

function buildConfiguration(
  provider: AiProviderData | null | undefined,
  fields: CredentialField[],
): Record<string, string> {
  const configuration: Record<string, string> = {};

  for (const field of fields) {
    if (field.secret) {
      configuration[field.field] = '';
      continue;
    }

    const value = provider?.credential_values[field.field];
    configuration[field.field] =
      value ?? (field.default as string | null) ?? '';
  }

  return configuration;
}

function buildFormDefaults(): ProviderForm {
  const brand = isEditMode.value
    ? (props.provider?.brand ?? '')
    : defaultBrand.value;
  const fields =
    isEditMode.value && props.provider
      ? (props.provider.credential_fields as CredentialField[])
      : credentialFieldsForBrand(brand);

  return {
    name: props.provider?.name ?? '',
    brand,
    configuration: buildConfiguration(props.provider, fields),
  };
}

const form = useForm<ProviderForm>(buildFormDefaults());

watch(
  [() => props.mode, () => props.provider, defaultBrand],
  () => {
    form.defaults(buildFormDefaults());
    form.reset();
    form.clearErrors();
    form.transform((data) => data);
  },
  { immediate: true },
);

watch(
  () => form.brand,
  () => {
    if (isEditMode.value) {
      return;
    }

    form.configuration = buildConfiguration(
      null,
      credentialFieldsForBrand(form.brand),
    );
    form.clearErrors();
  },
);

function setConfigurationValue(fieldName: string, value: unknown): void {
  form.configuration[fieldName] =
    typeof value === 'string' || typeof value === 'number' ? String(value) : '';
}

function fieldValue(fieldName: string): string {
  return form.configuration[fieldName] ?? '';
}

function fieldError(fieldName: string): string | undefined {
  return form.errors[`configuration.${fieldName}`];
}

function hasCredentialsToClear(): boolean {
  const provider = props.provider;
  if (!provider) {
    return false;
  }

  return (provider.credential_fields as CredentialField[]).some((field) => {
    if (field.secret) {
      return Boolean(provider.credential_masks[field.field]);
    }

    const value = provider.credential_values[field.field] ?? '';
    return value !== '' && value !== (field.default ?? '');
  });
}

function showClearCredentialsAction(field: CredentialField): boolean {
  return isEditMode.value && field.secret === true && hasCredentialsToClear();
}

function secretFieldPlaceholder(field: CredentialField): string | undefined {
  if (
    isEditMode.value &&
    field.secret === true &&
    Boolean(props.provider?.credential_masks[field.field])
  ) {
    return t('已配置，留空则保持不变');
  }

  return undefined;
}

function handleActionError(errors: Record<string, string | undefined>): void {
  if (typeof errors.toast === 'string' && errors.toast.trim().length > 0) {
    return;
  }

  const message = Object.values(errors).find(
    (value): value is string =>
      typeof value === 'string' && value.trim().length > 0,
  );

  if (message) {
    toast.warning(message);
  }
}

function submit(): void {
  if (isEditMode.value && props.provider) {
    form
      .transform((data) => ({
        name: data.name,
        configuration: data.configuration,
      }))
      .put(
        AiProvider.UpdateAiProviderCredentialsAction.url({
          provider: props.provider.id,
        }),
        {
          preserveScroll: true,
          onSuccess: () => emit('saved'),
          onError: (errors) =>
            handleActionError(errors as Record<string, string | undefined>),
        },
      );
    return;
  }

  form
    .transform((data) => ({
      name: data.name,
      brand: data.brand,
      configuration: data.configuration,
    }))
    .post(AiProvider.CreateAiProviderAction.url(), {
      preserveScroll: true,
      onSuccess: () => emit('saved'),
      onError: (errors) =>
        handleActionError(errors as Record<string, string | undefined>),
    });
}

const checkHttp = useHttp<CheckPayload, CheckResponse>({ configuration: {} });

function checkConnection(): void {
  if (!props.provider) {
    return;
  }

  checkHttp.configuration = form.configuration;
  checkHttp.post(
    AiProvider.CheckAiProviderAction.url({ provider: props.provider.id }),
    {
      onSuccess: (response: CheckResponse) => {
        if (response.success) {
          toast.success(response.message || t('连接测试成功'));
        } else {
          toast.error(response.message || t('连接测试失败'));
        }
      },
      onHttpException: () => {
        toast.error(t('请求失败，请稍后再试'));
      },
      onNetworkError: () => {
        toast.error(t('网络异常，请检查连接'));
      },
    },
  );
}
</script>

<template>
  <div class="w-full space-y-6">
    <HeadingSmall :title="title" :description="description" />

    <form class="space-y-6" @submit.prevent="submit">
      <FormField
        v-if="!isEditMode"
        :label="t('品牌')"
        label-for="ai-provider-brand"
        :error="form.errors.brand"
        required
      >
        <Select v-model="form.brand" required>
          <SelectTrigger id="ai-provider-brand" class="mt-1 w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in brandOptions"
              :key="option.brand"
              :value="option.brand"
            >
              {{ option.label }}
            </SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <FormField v-else :label="t('品牌')">
        <div class="mt-1 rounded-md border px-3 py-2 text-sm">
          {{ selectedBrandLabel }}
        </div>
      </FormField>

      <FormField
        :label="t('名称')"
        label-for="ai-provider-name"
        :error="form.errors.name"
        required
      >
        <Input
          id="ai-provider-name"
          v-model="form.name"
          class="mt-1 block w-full"
          autocomplete="off"
          maxlength="128"
          required
        />
      </FormField>

      <FormField
        v-for="field in selectedCredentialFields"
        :key="field.field"
        :label="field.label"
        :label-for="`ai-provider-${field.field}`"
        :error="fieldError(field.field)"
        :required="field.required"
      >
        <div v-if="field.secret" class="relative">
          <Input
            :id="`ai-provider-${field.field}`"
            :class="showClearCredentialsAction(field) ? 'pr-10' : ''"
            :model-value="fieldValue(field.field)"
            :placeholder="secretFieldPlaceholder(field)"
            type="password"
            autocomplete="off"
            :required="
              field.required && !props.provider?.credential_masks[field.field]
            "
            @update:model-value="
              (value) => setConfigurationValue(field.field, value)
            "
          />
          <button
            v-if="showClearCredentialsAction(field)"
            type="button"
            class="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground transition-colors hover:text-destructive"
            :title="t('清空凭据')"
            @click="emit('clearCredentials')"
          >
            <Trash2 class="h-4 w-4" />
            <span class="sr-only">{{ t('清空凭据') }}</span>
          </button>
        </div>

        <div
          v-else-if="isReadonlyField(field)"
          class="rounded-md border bg-muted/40 px-3 py-2 font-mono text-sm text-muted-foreground"
        >
          {{ fieldValue(field.field) }}
        </div>

        <Input
          v-else
          :id="`ai-provider-${field.field}`"
          :model-value="fieldValue(field.field)"
          :type="field.type === 'url' ? 'url' : 'text'"
          autocomplete="off"
          :required="field.required"
          @update:model-value="
            (value) => setConfigurationValue(field.field, value)
          "
        />
      </FormField>

      <FormActions
        :submit-label="submitLabel"
        :processing="form.processing"
        :submit-disabled="checkHttp.processing || !selectedBrand"
        :cancel-href="props.returnHref"
        :cancel-label="t('返回')"
      >
        <Button
          v-if="isEditMode"
          type="button"
          variant="outline"
          :disabled="checkHttp.processing || form.processing"
          @click="checkConnection"
        >
          <LoaderCircle
            v-if="checkHttp.processing"
            class="mr-2 h-4 w-4 animate-spin"
          />
          {{ t('测试') }}
        </Button>
      </FormActions>
    </form>
  </div>
</template>
