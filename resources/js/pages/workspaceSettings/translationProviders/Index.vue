<!--
  系统翻译供应商页面：左侧供应商列表，右侧承接详情、创建和编辑表单。
-->
<script setup lang="ts">
import Translation from '@/actions/App/Actions/Translation';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import AiProviderIcon from '@/components/icons/AiProviderIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type {
  ShowTranslationProviderPagePropsData,
  TranslationProviderData,
  TranslationResult,
} from '@/types/generated';
import { Head, router, useHttp } from '@inertiajs/vue3';
import { LoaderCircle, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import TranslationProviderFormPanel from './TranslationProviderFormPanel.vue';

type RightPage = 'provider_detail' | 'provider_form';
type ProviderFormMode = 'create' | 'edit';

type CredentialField = {
  field: string;
  label: string;
  type?: 'text' | 'password' | 'url';
  required?: boolean;
  secret?: boolean;
  default?: string | null;
};

type CheckPayload = {
  text: string;
  target_lang: string;
  source_lang: string | null;
  configuration: Record<string, string>;
};

type CheckResponse = {
  success: boolean;
  message: string;
  result: TranslationResult | null;
};

const props = defineProps<ShowTranslationProviderPagePropsData>();

const { t } = useI18n();
const { toast } = useToast();

const selectedProviderQueryParam = 'provider';
const panelQueryParam = 'panel';

function providerExists(slug: string | null): slug is string {
  return (
    slug !== null && props.providers.some((provider) => provider.slug === slug)
  );
}

function defaultProviderSlug(): string | null {
  return props.providers[0]?.slug ?? null;
}

function readSelectedProviderFromUrl(): string | null {
  if (typeof window === 'undefined') {
    return defaultProviderSlug();
  }

  const requested = new URLSearchParams(window.location.search).get(
    selectedProviderQueryParam,
  );

  return providerExists(requested) ? requested : defaultProviderSlug();
}

function readRightPanelFromUrl(selectedSlug: string | null): {
  page: RightPage;
  mode: ProviderFormMode;
  editingSlug: string | null;
} {
  if (typeof window === 'undefined') {
    return { page: 'provider_detail', mode: 'create', editingSlug: null };
  }

  const url = new URL(window.location.href);
  const panel = url.searchParams.get(panelQueryParam);

  if (panel === 'create') {
    return { page: 'provider_form', mode: 'create', editingSlug: null };
  }

  if (panel === 'edit' && providerExists(selectedSlug)) {
    return { page: 'provider_form', mode: 'edit', editingSlug: selectedSlug };
  }

  return { page: 'provider_detail', mode: 'create', editingSlug: null };
}

function writeUrlState(
  slug: string | null,
  page: RightPage,
  mode: ProviderFormMode,
  editingSlug: string | null,
): void {
  if (typeof window === 'undefined') {
    return;
  }

  const url = new URL(window.location.href);
  const providerSlug =
    page === 'provider_form' && mode === 'edit' ? (editingSlug ?? slug) : slug;

  if (providerSlug === null || providerSlug === defaultProviderSlug()) {
    url.searchParams.delete(selectedProviderQueryParam);
  } else {
    url.searchParams.set(selectedProviderQueryParam, providerSlug);
  }

  if (page === 'provider_form') {
    url.searchParams.set(panelQueryParam, mode);
  } else {
    url.searchParams.delete(panelQueryParam);
  }

  window.history.replaceState(window.history.state, '', url.toString());
}

const selectedSlug = ref<string | null>(readSelectedProviderFromUrl());
const initialRightPanel = readRightPanelFromUrl(selectedSlug.value);
const activeRightPage = ref<RightPage>(initialRightPanel.page);
const providerFormMode = ref<ProviderFormMode>(initialRightPanel.mode);
const editingProviderSlug = ref<string | null>(initialRightPanel.editingSlug);
const createBaselineSlugs = ref<string[] | null>(null);
const deleteTarget = ref<TranslationProviderData | null>(null);
const isDeleting = ref(false);
const clearCredentialsConfirmOpen = ref(false);
const isClearingCredentials = ref(false);

const selectedProvider = computed<TranslationProviderData | null>(
  () =>
    props.providers.find((provider) => provider.slug === selectedSlug.value) ??
    null,
);

const editingProvider = computed<TranslationProviderData | null>(() => {
  if (editingProviderSlug.value) {
    return (
      props.providers.find(
        (provider) => provider.slug === editingProviderSlug.value,
      ) ?? null
    );
  }

  return selectedProvider.value;
});

watch(
  [selectedSlug, activeRightPage, providerFormMode, editingProviderSlug],
  () => {
    writeUrlState(
      selectedSlug.value,
      activeRightPage.value,
      providerFormMode.value,
      editingProviderSlug.value,
    );
  },
);

watch(
  () => props.providers,
  (providers) => {
    if (
      selectedSlug.value &&
      !providers.find((provider) => provider.slug === selectedSlug.value)
    ) {
      selectedSlug.value = providers[0]?.slug ?? null;
      activeRightPage.value = 'provider_detail';
    } else if (!selectedSlug.value && providers.length > 0) {
      selectedSlug.value = providers[0].slug;
    }

    if (editingProviderSlug.value) {
      const editingStillExists = providers.some(
        (provider) => provider.slug === editingProviderSlug.value,
      );
      if (!editingStillExists) {
        editingProviderSlug.value = null;
        activeRightPage.value = 'provider_detail';
      }
    }

    selectCreatedProviderIfAvailable();
    writeUrlState(
      selectedSlug.value,
      activeRightPage.value,
      providerFormMode.value,
      editingProviderSlug.value,
    );
  },
);

onMounted(() => {
  writeUrlState(
    selectedSlug.value,
    activeRightPage.value,
    providerFormMode.value,
    editingProviderSlug.value,
  );
});

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

function selectProvider(provider: TranslationProviderData): void {
  selectedSlug.value = provider.slug;
  activeRightPage.value = 'provider_detail';
  editingProviderSlug.value = null;
}

function isProviderRowActive(provider: TranslationProviderData): boolean {
  if (
    activeRightPage.value === 'provider_form' &&
    providerFormMode.value === 'edit'
  ) {
    return editingProviderSlug.value === provider.slug;
  }

  return selectedSlug.value === provider.slug;
}

function openCreateForm(): void {
  createBaselineSlugs.value = props.providers.map((provider) => provider.slug);
  providerFormMode.value = 'create';
  editingProviderSlug.value = null;
  activeRightPage.value = 'provider_form';
}

function openEditForm(provider: TranslationProviderData): void {
  selectedSlug.value = provider.slug;
  createBaselineSlugs.value = null;
  providerFormMode.value = 'edit';
  editingProviderSlug.value = provider.slug;
  activeRightPage.value = 'provider_form';
}

function closeProviderForm(): void {
  activeRightPage.value = 'provider_detail';
  editingProviderSlug.value = null;
  createBaselineSlugs.value = null;
}

function selectCreatedProviderIfAvailable(): boolean {
  if (!createBaselineSlugs.value) {
    return false;
  }

  const baseline = new Set(createBaselineSlugs.value);
  const created = props.providers.find(
    (provider) => !baseline.has(provider.slug),
  );

  if (!created) {
    return false;
  }

  selectedSlug.value = created.slug;
  createBaselineSlugs.value = null;

  return true;
}

function handleProviderFormSaved(): void {
  if (providerFormMode.value === 'create') {
    selectCreatedProviderIfAvailable();
  }

  activeRightPage.value = 'provider_detail';
  editingProviderSlug.value = null;
}

const checkHttp = useHttp<CheckPayload, CheckResponse>({
  text: 'Hello',
  target_lang: 'zh-CN',
  source_lang: null,
  configuration: {},
});

function checkSavedProviderConnection(): void {
  if (!selectedProvider.value) {
    return;
  }

  const sampleText = 'Hello';
  checkHttp.text = sampleText;
  checkHttp.target_lang = 'zh-CN';
  checkHttp.source_lang = null;
  checkHttp.configuration = {};

  checkHttp.post(
    Translation.CheckTranslationProviderAction[
      '/admin/manage/translation/providers/{provider}/check'
    ].url({
      provider: selectedProvider.value.slug,
    }),
    {
      onSuccess: (response) => {
        if (response.success && response.result) {
          toast.success(
            `${sampleText} → ${response.result.text}（${response.result.latency_ms}ms）`,
          );
        } else {
          toast.error(response.message || t('翻译测试失败'));
        }
      },
      onHttpException: () => toast.error(t('请求失败，请稍后再试')),
      onNetworkError: () => toast.error(t('网络异常，请检查连接')),
    },
  );
}

function openClearCredentialsDialog(): void {
  if (!selectedProvider.value) {
    return;
  }

  clearCredentialsConfirmOpen.value = true;
}

function closeClearCredentialsDialog(open: boolean): void {
  if (open || isClearingCredentials.value) {
    return;
  }

  clearCredentialsConfirmOpen.value = false;
}

function clearCredentials(): void {
  if (!selectedSlug.value) {
    return;
  }

  isClearingCredentials.value = true;

  router.delete(
    Translation.ClearTranslationProviderCredentialsAction.url({
      provider: selectedSlug.value,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        clearCredentialsConfirmOpen.value = false;
      },
      onFinish: () => {
        isClearingCredentials.value = false;
      },
      onError: (errors) =>
        handleActionError(errors as Record<string, string | undefined>),
    },
  );
}

function openDeleteDialog(provider: TranslationProviderData): void {
  deleteTarget.value = provider;
}

function closeDeleteDialog(open: boolean): void {
  if (open || isDeleting.value) {
    return;
  }

  deleteTarget.value = null;
}

function confirmDelete(): void {
  if (!deleteTarget.value || isDeleting.value) {
    return;
  }

  isDeleting.value = true;

  router.delete(
    Translation.DeleteTranslationProviderAction.url({
      provider: deleteTarget.value.slug,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        if (deleteTarget.value?.slug === editingProviderSlug.value) {
          editingProviderSlug.value = null;
          activeRightPage.value = 'provider_detail';
        }
        deleteTarget.value = null;
      },
      onFinish: () => {
        isDeleting.value = false;
      },
      onError: (errors) =>
        handleActionError(errors as Record<string, string | undefined>),
    },
  );
}

const protocolIconMap: Record<string, string> = {
  'google-translate': 'google',
  deepl: 'deepl',
  'azure-translator': 'azure',
  'baidu-translate': 'baidu',
  'tencent-cloud-translate': 'tencent-cloud',
  'amazon-translate': 'aws',
};

function providerIcon(provider: TranslationProviderData): string | null {
  return provider.icon ?? protocolIconMap[provider.protocol] ?? null;
}

function hasAnyCredential(provider: TranslationProviderData): boolean {
  return (provider.credential_fields as CredentialField[]).some((field) => {
    if (field.secret) {
      return Boolean(provider.credential_masks[field.field]);
    }

    return Boolean(provider.credential_values[field.field]);
  });
}
</script>

<template>
  <AppLayout>
    <Head :title="t('翻译供应商')" />

    <SystemSettingsLayout>
      <section class="mx-auto w-full max-w-none space-y-12">
        <div
          class="flex h-[calc(100svh-7rem)] flex-col space-y-6 overflow-hidden md:h-[calc(100svh-4rem)]"
        >
          <HeadingSmall
            :title="t('翻译供应商')"
            :description="
              t(
                '为接待页消息双向翻译配置外部翻译服务的凭据；同时刻只有一家被设为当前使用。',
              )
            "
          />

          <div class="flex min-h-0 flex-1 rounded-xl border">
            <div class="flex w-64 shrink-0 flex-col border-r">
              <div class="flex items-center justify-between p-4">
                <h3 class="text-sm font-semibold">{{ t('供应商') }}</h3>
                <Button
                  type="button"
                  :variant="
                    activeRightPage === 'provider_form' &&
                    providerFormMode === 'create'
                      ? 'secondary'
                      : 'ghost'
                  "
                  size="icon"
                  class="h-7 w-7"
                  :aria-label="t('添加翻译供应商')"
                  @click="openCreateForm"
                >
                  <Plus class="h-4 w-4" />
                </Button>
              </div>

              <div class="flex-1 overflow-y-auto px-2 pb-4">
                <div
                  v-if="props.providers.length === 0"
                  class="px-4 py-8 text-center text-sm text-muted-foreground"
                >
                  {{ t('暂无供应商') }}
                </div>

                <div v-else class="space-y-0.5">
                  <div
                    v-for="provider in props.providers"
                    :key="provider.slug"
                    class="flex items-center gap-2 rounded-md text-sm transition-colors"
                    :class="
                      isProviderRowActive(provider)
                        ? 'bg-accent text-accent-foreground'
                        : 'hover:bg-muted'
                    "
                  >
                    <button
                      type="button"
                      class="flex min-w-0 flex-1 items-center gap-3 py-2 pl-3 text-left"
                      @click="selectProvider(provider)"
                    >
                      <AiProviderIcon
                        :icon="providerIcon(provider)"
                        class="h-7 w-7 shrink-0 rounded-md bg-muted p-1.5"
                      />
                      <div class="min-w-0 flex-1 space-y-0.5">
                        <span class="block truncate font-medium">
                          {{ provider.name }}
                        </span>
                        <span
                          class="block truncate text-[11px] text-muted-foreground"
                        >
                          {{ provider.protocol_label }}
                        </span>
                      </div>
                    </button>
                    <Badge
                      v-if="!provider.has_complete_credentials"
                      variant="outline"
                      class="mr-3 shrink-0 text-[11px] text-muted-foreground"
                    >
                      {{ t('凭据未配置完整') }}
                    </Badge>
                  </div>
                </div>
              </div>
            </div>

            <div class="flex-1 overflow-y-auto">
              <div
                v-if="activeRightPage === 'provider_form'"
                class="space-y-6 p-6"
              >
                <TranslationProviderFormPanel
                  :mode="providerFormMode"
                  :provider="
                    providerFormMode === 'edit' ? editingProvider : null
                  "
                  :protocol-options="props.protocol_options"
                  :protocol-credential-fields="props.protocol_credential_fields"
                  @cancel="closeProviderForm"
                  @saved="handleProviderFormSaved"
                  @clear-credentials="openClearCredentialsDialog"
                />
              </div>

              <template v-else-if="selectedProvider">
                <div class="space-y-6 p-6">
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-2">
                      <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold">
                          {{ selectedProvider.name }}
                        </h3>
                        <Badge variant="outline">
                          {{ selectedProvider.protocol_label }}
                        </Badge>
                      </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="checkHttp.processing"
                        @click="checkSavedProviderConnection"
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
                        size="sm"
                        @click="openEditForm(selectedProvider)"
                      >
                        {{ t('编辑') }}
                      </Button>
                      <Button
                        v-if="!selectedProvider.is_builtin"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="text-destructive hover:text-destructive"
                        :title="t('删除')"
                        :aria-label="t('删除')"
                        @click="openDeleteDialog(selectedProvider)"
                      >
                        <Trash2 class="h-4 w-4" />
                      </Button>
                    </div>
                  </div>

                  <Separator />

                  <div class="space-y-3">
                    <h3 class="text-sm font-semibold">
                      {{ t('连接配置') }}
                    </h3>
                    <div class="space-y-3 text-sm">
                      <div class="flex items-start gap-3">
                        <div
                          class="w-24 shrink-0 text-xs text-muted-foreground"
                        >
                          {{ t('名称') }}
                        </div>
                        <div class="min-w-0 flex-1 break-words">
                          {{ selectedProvider.name }}
                        </div>
                      </div>
                      <div class="flex items-start gap-3">
                        <div
                          class="w-24 shrink-0 text-xs text-muted-foreground"
                        >
                          {{ t('协议') }}
                        </div>
                        <div class="min-w-0 flex-1 break-words">
                          {{ selectedProvider.protocol_label }}
                        </div>
                      </div>
                      <div class="flex items-start gap-3">
                        <div
                          class="w-24 shrink-0 text-xs text-muted-foreground"
                        >
                          {{ t('凭据') }}
                        </div>
                        <div class="min-w-0 flex-1 break-words">
                          {{
                            hasAnyCredential(selectedProvider)
                              ? t('已配置')
                              : t('未配置')
                          }}
                        </div>
                      </div>
                    </div>
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

          <ConfirmDeleteDialog
            :open="clearCredentialsConfirmOpen"
            :title="t('确认清空凭据？')"
            :detail-title="selectedProvider?.name"
            :detail-description="
              t('清空后已保存的凭据将被移除，供应商也会被自动停用。')
            "
            :processing="isClearingCredentials"
            :confirm-label="t('确认清空')"
            :processing-label="t('清空中...')"
            @update:open="closeClearCredentialsDialog"
            @confirm="clearCredentials"
          />

          <ConfirmDeleteDialog
            :open="deleteTarget !== null"
            :title="t('确认删除供应商？')"
            :detail-title="deleteTarget?.name"
            :detail-description="
              t('确定要删除该翻译供应商吗？删除后无法恢复。')
            "
            :processing="isDeleting"
            @update:open="closeDeleteDialog"
            @confirm="confirmDelete"
          />
        </div>
      </section>
    </SystemSettingsLayout>
  </AppLayout>
</template>
