<!--
  文件说明：翻译供应商创建页面，承接供应商名称、协议、凭据和连接测试。
  消费后端 ShowCreateTranslationProviderPagePropsData。
-->
<script setup lang="ts">
import Translation from '@/actions/App/Actions/Translation';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type { ShowCreateTranslationProviderPagePropsData } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import TranslationProviderFormPanel from './TranslationProviderFormPanel.vue';

const props = defineProps<ShowCreateTranslationProviderPagePropsData>();
const { t } = useI18n();
</script>

<template>
  <AppLayout>
    <Head :title="t('添加翻译供应商')" />

    <SystemSettingsLayout>
      <TranslationProviderFormPanel
        mode="create"
        :protocol-options="props.protocol_options"
        :protocol-credential-fields="props.protocol_credential_fields"
        :return-href="Translation.ShowSystemTranslationProvidersAction.url()"
      />
    </SystemSettingsLayout>
  </AppLayout>
</template>
