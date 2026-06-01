<!--
  文件说明：创建接待方案页，承接方案基础信息 + 人设 + 接待/任务默认模型；
  复用 CreatePlanDialog 表单面板，创建成功后跳转到详情页继续完善配置。
  消费后端 CreateReceptionPlanPagePropsData。
-->
<script setup lang="ts">
import Plan from '@/actions/App/Actions/Reception/Plan';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import CreatePlanPanel from '@/pages/reception/plans/CreatePlanDialog.vue';
import type { CreateReceptionPlanPagePropsData } from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps<CreateReceptionPlanPagePropsData>();
const { t } = useI18n();
const currentWorkspace = useRequiredWorkspace();

function goToList(): void {
  router.visit(
    Plan.ShowReceptionPlanIndexPageAction.url(currentWorkspace.value.slug),
  );
}
</script>

<template>
  <AppLayout>
    <Head :title="t('创建接待方案')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="mx-auto w-full max-w-none space-y-12">
        <CreatePlanPanel
          :llm-model-options="props.llm_model_options"
          :persona-tone-options="props.persona_tone_options"
          @cancel="goToList"
        />
      </div>
    </div>
  </AppLayout>
</template>
