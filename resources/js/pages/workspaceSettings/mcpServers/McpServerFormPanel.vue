<!--
  MCP 服务创建/编辑表单面板，内嵌在 MCP 服务页右侧主内容区。
-->
<script setup lang="ts">
import Mcp from '@/actions/App/Actions/Mcp';
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
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import type { EnumOptionData, McpServerData } from '@/types/generated';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type AuthPreset = 'none' | 'bearer' | 'header';

type McpServerForm = {
  name: string;
  endpoint_url: string;
  transport: string;
  auth_header_name: string | null;
  auth_header_value: string | null;
  clear_auth_credentials: boolean;
  headers: Record<string, string> | null;
  timeout_seconds: number;
};

const props = defineProps<{
  mode: 'create' | 'edit';
  server?: McpServerData | null;
  transportOptions: EnumOptionData[];
}>();

const emit = defineEmits<{
  cancel: [];
  saved: [];
}>();

const { t } = useI18n();
const { toast } = useToast();
const workspace = useRequiredWorkspace();

const isEditMode = computed(() => props.mode === 'edit');

const title = computed(() =>
  isEditMode.value ? t('编辑 MCP 服务') : t('添加 MCP 服务'),
);

const description = computed(() =>
  isEditMode.value
    ? t('调整 MCP 服务的连接配置和认证信息。')
    : t('用 MCP 协议接入外部能力，供不同业务场景调用'),
);

const submitLabel = computed(() => (isEditMode.value ? t('保存') : t('添加')));

const defaultTransport = computed(
  () =>
    (props.transportOptions[0]?.value as string | undefined) ??
    'streamable_http',
);

const presetOptions = computed(() => [
  { value: 'none', label: t('不认证') },
  { value: 'bearer', label: t('持有者令牌') },
  { value: 'header', label: t('自定义请求头') },
]);

function detectPreset(server: McpServerData | null | undefined): AuthPreset {
  if (!server || !server.has_auth_credentials || !server.auth_header_name) {
    return 'none';
  }

  if (server.auth_header_name.toLowerCase() === 'authorization') {
    return 'bearer';
  }

  return 'header';
}

function buildFormDefaults(): McpServerForm {
  return {
    name: props.server?.name ?? '',
    endpoint_url: props.server?.endpoint_url ?? '',
    transport: props.server?.transport ?? defaultTransport.value,
    auth_header_name: null,
    auth_header_value: null,
    clear_auth_credentials: false,
    headers: props.server?.headers ?? null,
    timeout_seconds: props.server?.timeout_seconds ?? 30,
  };
}

const authPreset = ref<AuthPreset>(detectPreset(props.server));
const bearerToken = ref('');
const customHeaderName = ref('');
const customHeaderValue = ref('');
const isChecking = ref(false);

const form = useForm<McpServerForm>(buildFormDefaults());

watch(
  [() => props.mode, () => props.server, defaultTransport],
  () => {
    form.defaults(buildFormDefaults());
    form.reset();
    form.clearErrors();
    form.transform((data) => data);

    authPreset.value = detectPreset(props.server);
    bearerToken.value = '';
    customHeaderName.value =
      props.server && detectPreset(props.server) === 'header'
        ? (props.server.auth_header_name ?? '')
        : '';
    customHeaderValue.value = '';
  },
  { immediate: true },
);

function syncAuthFields(): void {
  form.clear_auth_credentials = false;

  if (authPreset.value === 'bearer') {
    if (bearerToken.value.trim() === '') {
      form.auth_header_name = null;
      form.auth_header_value = null;
    } else {
      form.auth_header_name = 'Authorization';
      form.auth_header_value = `Bearer ${bearerToken.value.trim()}`;
    }
  } else if (authPreset.value === 'header') {
    const headerName = customHeaderName.value.trim();
    const headerValue = customHeaderValue.value;

    if (
      isEditMode.value &&
      props.server?.has_auth_credentials &&
      headerValue === ''
    ) {
      form.auth_header_name = null;
      form.auth_header_value = null;
    } else if (isEditMode.value && headerName === '' && headerValue === '') {
      form.auth_header_name = null;
      form.auth_header_value = null;
    } else {
      form.auth_header_name = headerName;
      form.auth_header_value = headerValue;
    }
  } else {
    form.auth_header_name = null;
    form.auth_header_value = null;
    form.clear_auth_credentials = isEditMode.value;
  }
}

watch(
  [authPreset, bearerToken, customHeaderName, customHeaderValue, isEditMode],
  syncAuthFields,
);

function handleActionError(errors: Record<string, string | undefined>): void {
  const message = Object.values(errors).find(
    (value): value is string =>
      typeof value === 'string' && value.trim().length > 0,
  );

  if (message) {
    toast.warning(message);
  }
}

function fieldError(field: string): string | undefined {
  return (form.errors as Record<string, string | undefined>)[field];
}

function submit(): void {
  syncAuthFields();

  if (isEditMode.value && props.server) {
    form
      .transform((data) => ({
        name: data.name,
        endpoint_url: data.endpoint_url,
        auth_header_name: data.auth_header_name,
        auth_header_value: data.auth_header_value,
        clear_auth_credentials: data.clear_auth_credentials,
        headers: data.headers,
        timeout_seconds: data.timeout_seconds,
      }))
      .put(
        Mcp.UpdateMcpServerAction.url({
          slug: workspace.value.slug,
          server: props.server.slug,
        }),
        {
          preserveScroll: true,
          onSuccess: () => {
            bearerToken.value = '';
            customHeaderValue.value = '';
            emit('saved');
          },
          onError: (errors) =>
            handleActionError(errors as Record<string, string | undefined>),
        },
      );
    return;
  }

  form
    .transform((data) => ({
      name: data.name,
      endpoint_url: data.endpoint_url,
      transport: data.transport,
      auth_header_name: data.auth_header_name,
      auth_header_value: data.auth_header_value,
      headers: data.headers,
      timeout_seconds: data.timeout_seconds,
    }))
    .post(Mcp.CreateMcpServerAction.url(workspace.value.slug), {
      preserveScroll: true,
      onSuccess: () => {
        emit('saved');
      },
      onError: (errors) =>
        handleActionError(errors as Record<string, string | undefined>),
    });
}

async function checkConnection(): Promise<void> {
  syncAuthFields();
  isChecking.value = true;

  try {
    const payload = {
      name: form.name,
      endpoint_url: form.endpoint_url,
      transport: form.transport,
      auth_header_name: form.auth_header_name,
      auth_header_value: form.auth_header_value,
      clear_auth_credentials: form.clear_auth_credentials,
      headers: form.headers,
      timeout_seconds: form.timeout_seconds,
    };

    const { data } =
      isEditMode.value && props.server
        ? await axios.post(
            Mcp.CheckMcpServerAction[
              '/w/{slug}/manage/mcp-servers/{server}/check'
            ].url({
              slug: workspace.value.slug,
              server: props.server.slug,
            }),
            payload,
          )
        : await axios.post(
            Mcp.CheckMcpServerAction['/w/{slug}/manage/mcp-servers/check'].url(
              workspace.value.slug,
            ),
            payload,
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
}
</script>

<template>
  <div class="mx-auto w-full max-w-none space-y-6">
    <HeadingSmall :title="title" :description="description" />

    <form class="space-y-6" @submit.prevent="submit">
      <FormField
        :label="t('名称')"
        label-for="mcp-server-name"
        :error="fieldError('name')"
      >
        <Input
          id="mcp-server-name"
          v-model="form.name"
          class="mt-1 block w-full"
          autocomplete="off"
          maxlength="128"
        />
      </FormField>

      <FormField
        v-if="!isEditMode"
        :label="t('传输协议')"
        label-for="mcp-server-transport"
        :error="fieldError('transport')"
      >
        <Select v-model="form.transport">
          <SelectTrigger id="mcp-server-transport" class="mt-1 w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in props.transportOptions"
              :key="option.value"
              :value="String(option.value)"
            >
              {{ option.label }}
            </SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <FormField v-else :label="t('传输协议')">
        <div class="mt-1 rounded-md border px-3 py-2 text-sm">
          {{ props.server?.transport_label }}
        </div>
      </FormField>

      <FormField
        :label="t('端点地址')"
        label-for="mcp-server-endpoint-url"
        :error="fieldError('endpoint_url')"
      >
        <Input
          id="mcp-server-endpoint-url"
          v-model="form.endpoint_url"
          class="mt-1 block w-full"
          type="url"
          autocomplete="off"
          maxlength="2048"
        />
      </FormField>

      <FormField :label="t('认证方式')" label-for="mcp-server-auth-preset">
        <Select
          :model-value="authPreset"
          @update:model-value="
            (value) => (authPreset = String(value) as AuthPreset)
          "
        >
          <SelectTrigger id="mcp-server-auth-preset" class="mt-1 w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in presetOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <FormField
        v-if="authPreset === 'bearer'"
        :label="t('持有者令牌')"
        label-for="mcp-server-bearer-token"
        :error="fieldError('auth_header_value')"
      >
        <Input
          id="mcp-server-bearer-token"
          v-model="bearerToken"
          class="mt-1 block w-full"
          type="password"
          autocomplete="off"
        />
      </FormField>

      <template v-if="authPreset === 'header'">
        <FormField
          :label="t('认证 Header 名')"
          label-for="mcp-server-auth-header-name"
          :error="fieldError('auth_header_name')"
        >
          <Input
            id="mcp-server-auth-header-name"
            v-model="customHeaderName"
            class="mt-1 block w-full"
            autocomplete="off"
            maxlength="128"
          />
        </FormField>

        <FormField
          :label="t('认证 Header 值')"
          label-for="mcp-server-auth-header-value"
          :error="fieldError('auth_header_value')"
        >
          <Input
            id="mcp-server-auth-header-value"
            v-model="customHeaderValue"
            class="mt-1 block w-full"
            type="password"
            autocomplete="off"
          />
        </FormField>
      </template>

      <FormField
        :label="t('超时（秒）')"
        label-for="mcp-server-timeout"
        :error="fieldError('timeout_seconds')"
      >
        <Input
          id="mcp-server-timeout"
          v-model.number="form.timeout_seconds"
          class="mt-1 block w-full"
          type="number"
          min="1"
          max="120"
        />
      </FormField>

      <FormActions
        :submit-label="submitLabel"
        :processing="form.processing"
        :submit-disabled="isChecking"
      >
        <Button
          type="button"
          variant="outline"
          :disabled="isChecking || form.processing"
          @click="checkConnection"
        >
          <LoaderCircle v-if="isChecking" class="mr-2 h-4 w-4 animate-spin" />
          {{ t('测试') }}
        </Button>
        <Button
          type="button"
          variant="outline"
          :disabled="form.processing || isChecking"
          @click="emit('cancel')"
        >
          {{ t('取消') }}
        </Button>
      </FormActions>
    </form>
  </div>
</template>
