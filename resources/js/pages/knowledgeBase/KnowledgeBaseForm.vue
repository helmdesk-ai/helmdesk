<!--
  知识库创建/编辑表单组件，用于独立的知识库创建/编辑页面，
  承接 KnowledgeBaseFormPanel 相同的表单字段但在独立页面中展示。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import FormActions from '@/components/common/FormActions.vue';
import FormField from '@/components/common/FormField.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import ImageUploadField from '@/components/common/ImageUploadField.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import { defaultKnowledgeBaseAvatar } from '@/lib/knowledgeBaseAvatar';
import type { KnowledgeBaseData } from '@/types/generated';
import type { RouteFormDefinition } from '@/wayfinder';
import { Form } from '@inertiajs/vue3';
import { computed } from 'vue';

type KnowledgeBaseFormDefinition =
  | RouteFormDefinition<'post'>
  | RouteFormDefinition<'put'>;

withDefaults(
  defineProps<{
    formDefinition: KnowledgeBaseFormDefinition;
    title: string;
    description: string;
    submitLabel: string;
    knowledgeBaseForm?: KnowledgeBaseData | null;
  }>(),
  {
    knowledgeBaseForm: null,
  },
);

const { t } = useI18n();

const listHref = computed(() => KnowledgeBase.ListKnowledgeBasesAction.url());
</script>

<template>
  <div class="space-y-6">
    <HeadingSmall :title="title" :description="description" />

    <Form
      v-bind="formDefinition"
      class="space-y-6"
      v-slot="{ errors, processing }"
    >
      <input
        type="hidden"
        name="category"
        :value="knowledgeBaseForm?.category ?? 'standard'"
      />

      <FormField :label="t('知识库名称')" label-for="name" :error="errors.name">
        <Input
          id="name"
          name="name"
          class="mt-1 block w-full"
          :default-value="knowledgeBaseForm?.name"
          maxlength="120"
        />
      </FormField>

      <FormField
        :label="t('描述')"
        label-for="description"
        :error="errors.description"
      >
        <Textarea
          id="description"
          name="description"
          rows="5"
          :default-value="knowledgeBaseForm?.description ?? ''"
          class="mt-1 min-h-32"
        />
      </FormField>

      <ImageUploadField
        :label="t('知识库头像')"
        name="avatar_id"
        purpose="avatar"
        :initial-preview="
          knowledgeBaseForm?.avatar_url ?? defaultKnowledgeBaseAvatar
        "
        :initial-value="knowledgeBaseForm?.avatar_id ?? ''"
        variant="logo"
        :error="errors.avatar_id"
      />

      <FormActions
        :submit-label="submitLabel"
        :processing="processing"
        :cancel-href="listHref"
        :cancel-label="t('取消')"
      />
    </Form>
  </div>
</template>
