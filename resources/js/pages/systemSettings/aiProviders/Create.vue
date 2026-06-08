<!--
  文件说明：AI 供应商创建页面，承接品牌选择与凭据录入。
  消费后端 ShowCreateAiProviderPagePropsData。
-->
<script setup lang="ts">
import AiProvider from '@/actions/App/Actions/AiProvider';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type { ShowCreateAiProviderPagePropsData } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import AiProviderFormPanel from './AiProviderFormPanel.vue';

const props = defineProps<ShowCreateAiProviderPagePropsData>();
const { t } = useI18n();
</script>

<template>
  <AppLayout>
    <Head :title="t('新增 AI 供应商')" />

    <SystemSettingsLayout>
      <AiProviderFormPanel
        mode="create"
        :brand-options="props.brand_options"
        :return-href="AiProvider.ShowAiProviderListAction.url()"
      />
    </SystemSettingsLayout>
  </AppLayout>
</template>
