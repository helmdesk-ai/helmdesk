<!--
  文件说明：编辑知识库页面。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import KnowledgeBasesLayout from '@/layouts/KnowledgeBasesLayout.vue';
import KnowledgeBaseForm from '@/pages/knowledgeBase/KnowledgeBaseForm.vue';
import type { ShowEditKnowledgeBasePagePropsData } from '@/types/generated';
import { Head } from '@inertiajs/vue3';

const props = defineProps<ShowEditKnowledgeBasePagePropsData>();
const { t } = useI18n();
</script>

<template>
  <AppLayout>
    <Head :title="t('编辑知识库')" />

    <KnowledgeBasesLayout>
      <KnowledgeBaseForm
        :form-definition="{
          action: KnowledgeBase.UpdateKnowledgeBaseAction.url({
            knowledgeBase: props.knowledge_base_form.id,
          }),
          method: 'put',
        }"
        :title="t('编辑知识库')"
        :description="t('调整知识库基础信息。')"
        :submit-label="t('保存')"
        :knowledge-base-form="props.knowledge_base_form"
      />
    </KnowledgeBasesLayout>
  </AppLayout>
</template>
