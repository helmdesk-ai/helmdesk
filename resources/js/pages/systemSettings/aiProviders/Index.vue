<!--
  文件说明：系统 AI 供应商列表页面，纯凭据管理（对齐翻译供应商），承接供应商列表、编辑与删除。
  模型在「AI 模型管理」页维护。消费后端 ShowAiProviderListPagePropsData。
-->
<script setup lang="ts">
import AiProvider from '@/actions/App/Actions/AiProvider';
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
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type {
  AiProviderData,
  ShowAiProviderListPagePropsData,
} from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MoreHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<ShowAiProviderListPagePropsData>();

const { t } = useI18n();

const deleteForm = useForm({});
const deletingProviderId = ref<string | null>(null);

const deletingProvider = computed(
  () =>
    props.providers.find(
      (provider) => provider.id === deletingProviderId.value,
    ) ?? null,
);

function openDeleteDialog(provider: AiProviderData): void {
  deletingProviderId.value = provider.id;
}

function handleDeleteDialogOpenChange(open: boolean): void {
  if (!open) {
    deletingProviderId.value = null;
  }
}

function confirmDelete(): void {
  if (!deletingProvider.value || deleteForm.processing) {
    return;
  }

  deleteForm.delete(
    AiProvider.DeleteAiProviderAction.url({
      provider: deletingProvider.value.slug,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deletingProviderId.value = null;
      },
    },
  );
}
</script>

<template>
  <AppLayout>
    <Head :title="t('AI 供应商')" />

    <SystemSettingsLayout content-class="max-w-none">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('AI 供应商')"
            :description="
              t(
                '系统级 AI 服务凭据，跨工作区共享。模型在「AI 模型管理」页维护。',
              )
            "
          />

          <Button as-child>
            <Link :href="AiProvider.ShowCreateAiProviderPageAction.url()">
              {{ t('新增 AI 供应商') }}
            </Link>
          </Button>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('名称') }}</th>
                  <th class="px-4 py-3">{{ t('品牌') }}</th>
                  <th class="px-4 py-3">Base URL</th>
                  <th class="w-44 px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="provider in props.providers"
                  :key="provider.id"
                  class="border-t bg-background align-middle"
                >
                  <td class="px-4 py-3">
                    <span class="truncate font-medium">{{
                      provider.name
                    }}</span>
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    {{ provider.brand_label }}
                  </td>

                  <td class="px-4 py-3 font-mono text-xs text-muted-foreground">
                    {{ provider.base_url ?? '—' }}
                  </td>

                  <td class="w-44 px-4 py-3">
                    <div class="flex justify-end gap-2 whitespace-nowrap">
                      <Button size="sm" variant="outline" as-child>
                        <Link
                          :href="
                            AiProvider.ShowEditAiProviderPageAction.url({
                              provider: provider.slug,
                            })
                          "
                        >
                          {{ t('编辑') }}
                        </Link>
                      </Button>

                      <DropdownMenu>
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
                    colspan="4"
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
          :open="deletingProviderId !== null"
          :title="t('确认删除该 AI 供应商？')"
          :detail-title="deletingProvider?.name"
          :detail-description="
            t('删除后该供应商及其下所有模型立即移出全局取用池，且无法恢复。')
          "
          :processing="deleteForm.processing"
          @update:open="handleDeleteDialogOpenChange"
          @confirm="confirmDelete"
        />
      </div>
    </SystemSettingsLayout>
  </AppLayout>
</template>
