<!--
  文件说明：「编辑 AI 模型」页，供应商/用途/模型 ID 只读，仅承接显示名称与启用调整。
  消费后端 ShowEditAiModelPagePropsData。
-->
<script setup lang="ts">
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type { ShowEditAiModelPagePropsData } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import ModelForm from './ModelForm.vue';

const props = defineProps<ShowEditAiModelPagePropsData>();
const { t } = useI18n();
</script>

<template>
  <AppLayout>
    <Head :title="t('编辑模型')" />

    <SystemSettingsLayout>
      <div class="w-full space-y-6">
        <HeadingSmall
          :title="t('编辑模型')"
          :description="t('调整模型的显示名称与启用状态。')"
        />

        <ModelForm mode="edit" :model="props.model" />
      </div>
    </SystemSettingsLayout>
  </AppLayout>
</template>
