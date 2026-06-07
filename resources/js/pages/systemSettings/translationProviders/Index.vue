<!--
  文件说明：系统翻译供应商列表页面，承接供应商列表、连接测试和删除操作。
  消费后端 ShowTranslationProviderPagePropsData。
-->
<script setup lang="ts">
import Translation from '@/actions/App/Actions/Translation';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type {
  ShowTranslationProviderPagePropsData,
  TranslationProviderData,
} from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { LoaderCircle, MoreHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';

type CheckResponse = {
  success: boolean;
  message: string;
  result: {
    text: string;
    latency_ms: number;
  } | null;
};

const props = defineProps<ShowTranslationProviderPagePropsData>();

const { t } = useI18n();
const { toast } = useToast();

const deleteForm = useForm({});
const deletingProviderSlug = ref<string | null>(null);
const checkingProviderSlug = ref<string | null>(null);

const deletingProvider = computed(
  () =>
    props.providers.find(
      (provider) => provider.slug === deletingProviderSlug.value,
    ) ?? null,
);

async function checkConnection(
  provider: TranslationProviderData,
): Promise<void> {
  checkingProviderSlug.value = provider.slug;

  try {
    const { data } = await axios.post<CheckResponse>(
      Translation.CheckTranslationProviderAction[
        '/admin/manage/translation/providers/{provider}/check'
      ].url({
        provider: provider.slug,
      }),
      {
        text: 'Hello',
        target_lang: 'zh-CN',
        source_lang: null,
        configuration: {},
      },
    );

    if (data.success && data.result) {
      toast.success(
        `Hello → ${data.result.text}（${data.result.latency_ms}ms）`,
      );
    } else {
      toast.error(data.message || t('翻译测试失败'));
    }
  } catch {
    // 失败响应由全局 axios interceptor 统一处理。
  } finally {
    checkingProviderSlug.value = null;
  }
}

function openDeleteDialog(provider: TranslationProviderData): void {
  deletingProviderSlug.value = provider.slug;
}

function handleDeleteDialogOpenChange(open: boolean): void {
  if (!open) {
    deletingProviderSlug.value = null;
  }
}

function confirmDelete(): void {
  if (!deletingProvider.value || deleteForm.processing) {
    return;
  }

  deleteForm.delete(
    Translation.DeleteTranslationProviderAction.url({
      provider: deletingProvider.value.slug,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deletingProviderSlug.value = null;
      },
    },
  );
}
</script>

<template>
  <AppLayout>
    <Head :title="t('翻译供应商')" />

    <SystemSettingsLayout content-class="max-w-none">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('翻译供应商')"
            :description="
              t(
                '为接待页消息双向翻译配置外部翻译服务的凭据；同时刻只有一家被设为当前使用。',
              )
            "
          />

          <Button as-child>
            <Link
              :href="Translation.ShowCreateTranslationProviderPageAction.url()"
            >
              {{ t('新增翻译供应商') }}
            </Link>
          </Button>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('名称') }}</th>
                  <th class="px-4 py-3">{{ t('协议') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="provider in props.providers"
                  :key="provider.id"
                  class="border-t bg-background align-middle"
                >
                  <td class="px-4 py-3">
                    <span class="truncate font-medium">
                      {{ provider.name }}
                    </span>
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    {{ provider.protocol_label }}
                  </td>

                  <td class="px-4 py-3">
                    <div class="flex justify-end gap-2">
                      <Button size="sm" variant="outline" as-child>
                        <Link
                          :href="
                            Translation.ShowEditTranslationProviderPageAction.url(
                              {
                                provider: provider.slug,
                              },
                            )
                          "
                        >
                          {{ t('编辑') }}
                        </Link>
                      </Button>

                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="checkingProviderSlug === provider.slug"
                        @click="checkConnection(provider)"
                      >
                        <LoaderCircle
                          v-if="checkingProviderSlug === provider.slug"
                          class="mr-2 h-4 w-4 animate-spin"
                        />
                        {{ t('测试') }}
                      </Button>

                      <DropdownMenu v-if="!provider.is_builtin">
                        <DropdownMenuTrigger as-child>
                          <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :aria-label="t('更多操作')"
                          >
                            <MoreHorizontal class="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-36">
                          <DropdownMenuItem
                            class="text-destructive focus:text-destructive"
                            @select="openDeleteDialog(provider)"
                          >
                            {{ t('删除') }}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>

                <tr v-if="props.providers.length === 0">
                  <td
                    colspan="5"
                    class="px-4 py-8 text-center text-muted-foreground"
                  >
                    {{ t('暂无供应商') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <ConfirmDeleteDialog
          :open="deletingProviderSlug !== null"
          :title="t('确认删除供应商？')"
          :detail-title="deletingProvider?.name"
          :detail-description="t('确定要删除该翻译供应商吗？删除后无法恢复。')"
          :processing="deleteForm.processing"
          @update:open="handleDeleteDialogOpenChange"
          @confirm="confirmDelete"
        />
      </div>
    </SystemSettingsLayout>
  </AppLayout>
</template>
