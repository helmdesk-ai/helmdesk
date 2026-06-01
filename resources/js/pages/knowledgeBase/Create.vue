<!--
  文件说明：创建知识库页面。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import KnowledgeBasesLayout from '@/layouts/KnowledgeBasesLayout.vue';
import KnowledgeBaseForm from '@/pages/knowledgeBase/KnowledgeBaseForm.vue';
import { Head } from '@inertiajs/vue3';

const { t } = useI18n();
const currentWorkspace = useRequiredWorkspace();
</script>

<template>
  <AppLayout>
    <Head :title="t('创建知识库')" />

    <KnowledgeBasesLayout>
      <KnowledgeBaseForm
        :form-definition="{
          action: KnowledgeBase.CreateKnowledgeBaseAction.url(
            currentWorkspace.slug,
          ),
          method: 'post',
        }"
        :title="t('创建知识库')"
        :description="t('为当前工作区添加一个知识库。')"
        :submit-label="t('创建')"
      />
    </KnowledgeBasesLayout>
  </AppLayout>
</template>
