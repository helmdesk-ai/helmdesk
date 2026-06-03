<!--
  文件说明：翻译供应商编辑页面，承接供应商名称、凭据保存、清空凭据和连接测试。
  消费后端 ShowEditTranslationProviderPagePropsData。
-->
<script setup lang="ts">
import Translation from '@/actions/App/Actions/Translation';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type { ShowEditTranslationProviderPagePropsData } from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TranslationProviderFormPanel from './TranslationProviderFormPanel.vue';

const props = defineProps<ShowEditTranslationProviderPagePropsData>();
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
    Translation.ClearTranslationProviderCredentialsAction.url({
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
    <Head :title="t('编辑翻译供应商')" />

    <SystemSettingsLayout>
      <TranslationProviderFormPanel
        mode="edit"
        :provider="props.provider"
        :protocol-options="props.protocol_options"
        :protocol-credential-fields="props.protocol_credential_fields"
        :return-href="Translation.ShowSystemTranslationProvidersAction.url()"
        @clear-credentials="openClearCredentialsDialog"
      />

      <ConfirmDeleteDialog
        :open="clearCredentialsConfirmOpen"
        :title="t('确认清空凭据？')"
        :detail-title="props.provider.name"
        :detail-description="
          t('清空后已保存的凭据将被移除，接待方案将无法继续使用该供应商翻译。')
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
