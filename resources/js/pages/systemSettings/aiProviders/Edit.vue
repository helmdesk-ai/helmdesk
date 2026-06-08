<!--
  文件说明：AI 供应商编辑页面，承接名称、凭据保存、清空凭据与连通测试。
  消费后端 ShowEditAiProviderPagePropsData。
-->
<script setup lang="ts">
import AiProvider from '@/actions/App/Actions/AiProvider';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type { ShowEditAiProviderPagePropsData } from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AiProviderFormPanel from './AiProviderFormPanel.vue';

const props = defineProps<ShowEditAiProviderPagePropsData>();
const { t } = useI18n();

const clearCredentialsConfirmOpen = ref(false);
const isClearingCredentials = ref(false);

function openClearCredentialsDialog(): void {
  clearCredentialsConfirmOpen.value = true;
}

function closeClearCredentialsDialog(open: boolean): void {
  if (open || isClearingCredentials.value) {
    return;
  }

  clearCredentialsConfirmOpen.value = false;
}

function clearCredentials(): void {
  isClearingCredentials.value = true;

  router.delete(
    AiProvider.ClearAiProviderCredentialsAction.url({
      provider: props.provider.slug,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        clearCredentialsConfirmOpen.value = false;
      },
      onFinish: () => {
        isClearingCredentials.value = false;
      },
    },
  );
}
</script>

<template>
  <AppLayout>
    <Head :title="t('编辑 AI 供应商')" />

    <SystemSettingsLayout>
      <AiProviderFormPanel
        mode="edit"
        :provider="props.provider"
        :return-href="AiProvider.ShowAiProviderListAction.url()"
        @clear-credentials="openClearCredentialsDialog"
      />

      <ConfirmDeleteDialog
        :open="clearCredentialsConfirmOpen"
        :title="t('确认清空凭据？')"
        :detail-title="props.provider.name"
        :detail-description="
          t('清空后已保存的凭据将被移除，该供应商下的模型将无法继续调用。')
        "
        :processing="isClearingCredentials"
        :confirm-label="t('确认清空')"
        :processing-label="t('清空中...')"
        @update:open="closeClearCredentialsDialog"
        @confirm="clearCredentials"
      />
    </SystemSettingsLayout>
  </AppLayout>
</template>
