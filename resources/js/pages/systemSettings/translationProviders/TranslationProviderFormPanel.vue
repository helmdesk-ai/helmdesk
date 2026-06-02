<!--
  系统翻译供应商创建/编辑表单面板，内嵌在翻译供应商页右侧主内容区。
-->
<script setup lang="ts">
import Translation from '@/actions/App/Actions/Translation';
import FormActions from '@/components/common/FormActions.vue';
import FormField from '@/components/common/FormField.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import AiProviderIcon from '@/components/icons/AiProviderIcon.vue';
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
import type {
  EnumOptionData,
  TranslationProviderData,
  TranslationProviderType,
  TranslationResult,
} from '@/types/generated';
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
  protocol: TranslationProviderType | '';
  configuration: Record<string, string>;
};

type CheckPayload = {
  text: string;
  target_lang: string;
  source_lang: string | null;
  protocol?: TranslationProviderType | '';
  configuration: Record<string, string>;
};

type CheckResponse = {
  success: boolean;
  message: string;
  result: TranslationResult | null;
};

const props = defineProps<{
  mode: 'create' | 'edit';
  provider?: TranslationProviderData | null;
  protocolOptions: EnumOptionData[];
  protocolCredentialFields: Record<
    string,
    CredentialField[] | Record<number, CredentialField>
  >;
}>();

const emit = defineEmits<{
  cancel: [];
  saved: [];
  clearCredentials: [];
}>();

const { t } = useI18n();
const { toast } = useToast();

const isEditMode = computed(() => props.mode === 'edit');

const defaultProtocol = computed(
  () =>
    (props.protocolOptions[0]?.value as TranslationProviderType | undefined) ??
    '',
);

const selectedProtocol = computed<TranslationProviderType | ''>(() =>
  isEditMode.value ? (props.provider?.protocol ?? '') : form.protocol,
);

const selectedProtocolOption = computed(() =>
  props.protocolOptions.find(
    (option) => option.value === selectedProtocol.value,
  ),
);

const selectedProtocolLabel = computed(() => {
  if (isEditMode.value) {
    return props.provider?.protocol_label ?? '';
  }

  return selectedProtocolOption.value?.label ?? '';
});

const selectedCredentialFields = computed<CredentialField[]>(() => {
  if (!selectedProtocol.value) {
    return [];
  }

  if (isEditMode.value && props.provider) {
    return props.provider.credential_fields as CredentialField[];
  }

  return credentialFieldsForProtocol(selectedProtocol.value);
});

const title = computed(() =>
  isEditMode.value ? t('编辑翻译供应商') : t('添加翻译供应商'),
);

const description = computed(() =>
  isEditMode.value
    ? t('调整翻译供应商的名称和凭据。')
    : t('配置外部翻译服务的协议和凭据。'),
);

const submitLabel = computed(() => (isEditMode.value ? t('保存') : t('添加')));

const protocolIconMap: Record<string, string> = {
  'google-translate': 'google',
  deepl: 'deepl',
  'azure-translator': 'azure',
  'baidu-translate': 'baidu',
  'tencent-cloud-translate': 'tencent-cloud',
  'amazon-translate': 'aws',
};

function protocolIcon(protocol: string | null | undefined): string | null {
  return protocol ? (protocolIconMap[protocol] ?? null) : null;
}

function credentialFieldsForProtocol(
  protocol: TranslationProviderType | '',
): CredentialField[] {
  if (!protocol) {
    return [];
  }

  return Object.values(props.protocolCredentialFields[protocol] ?? {});
}

function buildConfiguration(
  provider: TranslationProviderData | null | undefined,
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
  const protocol = isEditMode.value
    ? (props.provider?.protocol ?? '')
    : defaultProtocol.value;
  const fields = protocol
    ? isEditMode.value && props.provider
      ? (props.provider.credential_fields as CredentialField[])
      : credentialFieldsForProtocol(protocol)
    : [];

  return {
    name: props.provider?.name ?? '',
    protocol,
    configuration: buildConfiguration(props.provider, fields),
  };
}

const form = useForm<ProviderForm>(buildFormDefaults());

watch(
  [() => props.mode, () => props.provider, defaultProtocol],
  () => {
    form.defaults(buildFormDefaults());
    form.reset();
    form.clearErrors();
    form.transform((data) => data);
  },
  { immediate: true },
);

watch(
  () => form.protocol,
  () => {
    if (isEditMode.value) {
      return;
    }

    form.configuration = buildConfiguration(
      null,
      selectedCredentialFields.value,
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
        Translation.UpdateTranslationProviderCredentialsAction.url({
          provider: props.provider.slug,
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
      protocol: data.protocol,
      configuration: data.configuration,
    }))
    .post(Translation.CreateTranslationProviderAction.url(), {
      preserveScroll: true,
      onSuccess: () => emit('saved'),
      onError: (errors) =>
        handleActionError(errors as Record<string, string | undefined>),
    });
}

const checkHttp = useHttp<CheckPayload, CheckResponse>({
  text: 'Hello',
  target_lang: 'zh-CN',
  source_lang: null,
  protocol: '',
  configuration: {},
});

function checkConnection(): void {
  const sampleText = 'Hello';
  checkHttp.text = sampleText;
  checkHttp.target_lang = 'zh-CN';
  checkHttp.source_lang = null;
  checkHttp.configuration = form.configuration;

  const onSuccess = (response: CheckResponse) => {
    if (response.success && response.result) {
      toast.success(
        `${sampleText} → ${response.result.text}（${response.result.latency_ms}ms）`,
      );
    } else {
      toast.error(response.message || t('翻译测试失败'));
    }
  };

  const errorHandlers = {
    onSuccess,
    onHttpException: () => toast.error(t('请求失败，请稍后再试')),
    onNetworkError: () => toast.error(t('网络异常，请检查连接')),
  };

  if (isEditMode.value && props.provider) {
    checkHttp.post(
      Translation.CheckTranslationProviderAction[
        '/admin/manage/translation/providers/{provider}/check'
      ].url({
        provider: props.provider.slug,
      }),
      errorHandlers,
    );
    return;
  }

  checkHttp.protocol = form.protocol;
  checkHttp.post(
    Translation.CheckTranslationProviderAction[
      '/admin/manage/translation/providers/check'
    ].url(),
    errorHandlers,
  );
}
</script>

<template>
  <div class="mx-auto w-full max-w-none space-y-6">
    <HeadingSmall :title="title" :description="description" />

    <form class="space-y-6" @submit.prevent="submit">
      <FormField
        :label="t('名称')"
        label-for="translation-provider-name"
        :error="form.errors.name"
        required
      >
        <Input
          id="translation-provider-name"
          v-model="form.name"
          class="mt-1 block w-full"
          autocomplete="off"
          maxlength="128"
          required
        />
      </FormField>

      <FormField
        v-if="!isEditMode"
        :label="t('协议')"
        label-for="translation-provider-protocol"
        :error="form.errors.protocol"
        required
      >
        <Select v-model="form.protocol" required>
          <SelectTrigger id="translation-provider-protocol" class="mt-1 w-full">
            <div class="flex min-w-0 items-center gap-2">
              <AiProviderIcon
                v-if="form.protocol"
                :icon="protocolIcon(form.protocol)"
                class="h-4 w-4 shrink-0"
              />
              <SelectValue />
            </div>
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in props.protocolOptions"
              :key="option.value"
              :value="String(option.value)"
            >
              <div class="flex items-center gap-2">
                <AiProviderIcon
                  :icon="protocolIcon(String(option.value))"
                  class="h-4 w-4 shrink-0"
                />
                <span>{{ option.label }}</span>
              </div>
            </SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <FormField v-else :label="t('协议')">
        <div
          class="mt-1 flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
        >
          <AiProviderIcon
            :icon="props.provider?.icon ?? protocolIcon(selectedProtocol)"
            class="h-4 w-4 shrink-0"
          />
          <span>{{ selectedProtocolLabel }}</span>
        </div>
      </FormField>

      <FormField
        v-for="field in selectedCredentialFields"
        :key="field.field"
        :label="field.label"
        :label-for="`translation-provider-${field.field}`"
        :error="fieldError(field.field)"
        :required="field.required"
      >
        <div v-if="field.secret" class="relative">
          <Input
            :id="`translation-provider-${field.field}`"
            :class="showClearCredentialsAction(field) ? 'pr-10' : ''"
            :model-value="fieldValue(field.field)"
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

        <Input
          v-else
          :id="`translation-provider-${field.field}`"
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
        :submit-disabled="checkHttp.processing || !form.protocol"
      >
        <Button
          type="button"
          variant="outline"
          :disabled="checkHttp.processing || form.processing || !form.protocol"
          @click="checkConnection"
        >
          <LoaderCircle
            v-if="checkHttp.processing"
            class="mr-2 h-4 w-4 animate-spin"
          />
          {{ t('测试') }}
        </Button>
        <Button
          type="button"
          variant="outline"
          :disabled="form.processing || checkHttp.processing"
          @click="emit('cancel')"
        >
          {{ t('取消') }}
        </Button>
      </FormActions>
    </form>
  </div>
</template>
