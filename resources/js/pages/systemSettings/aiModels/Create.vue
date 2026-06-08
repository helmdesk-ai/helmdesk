<!--
  文件说明：「新增 AI 模型」页，承接供应商/用途/模型 ID/名称录入。
  消费后端 ShowCreateAiModelPagePropsData。
-->
<script setup lang="ts">
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type { ShowCreateAiModelPagePropsData } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import ModelForm from './ModelForm.vue';

const props = defineProps<ShowCreateAiModelPagePropsData>();
const { t } = useI18n();
</script>

<template>
  <AppLayout>
    <Head :title="t('新增模型')" />

    <SystemSettingsLayout>
      <div class="w-full space-y-6">
        <HeadingSmall
          :title="t('新增模型')"
          :description="t('选择供应商与用途，填写模型 ID 与显示名称。')"
        />

        <ModelForm
          mode="create"
          :provider-options="props.provider_options"
          :purpose-options="props.purpose_options"
          :default-models-by-brand="props.default_models_by_brand"
        />
      </div>
    </SystemSettingsLayout>
  </AppLayout>
</template>
