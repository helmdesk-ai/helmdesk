<!--
  知识库创建/编辑表单面板，内嵌在知识库列表右侧区域，承接创建和编辑知识库的表单交互。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import FormActions from '@/components/common/FormActions.vue';
import FormField from '@/components/common/FormField.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import ImageUploadField from '@/components/common/ImageUploadField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import { defaultKnowledgeBaseAvatar } from '@/lib/knowledgeBaseAvatar';
import type {
  KnowledgeBaseCategory,
  KnowledgeBaseData,
} from '@/types/generated';
import type { RouteFormDefinition } from '@/wayfinder';
import { Form } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
  mode: 'create' | 'edit';
  category: KnowledgeBaseCategory;
  categoryLabel: string;
  knowledgeBase?: KnowledgeBaseData | null;
}>();

const emit = defineEmits<{
  cancel: [];
  saved: [];
}>();

const { t } = useI18n();

const isEditMode = computed(() => props.mode === 'edit');

const title = computed(() =>
  isEditMode.value ? t('编辑知识库') : t('创建知识库'),
);

const submitLabel = computed(() => (isEditMode.value ? t('保存') : t('创建')));

const formDef = computed<RouteFormDefinition<'post'>>(() => {
  if (isEditMode.value && props.knowledgeBase) {
    return KnowledgeBase.UpdateKnowledgeBaseAction.form({
      knowledgeBase: props.knowledgeBase.id,
    });
  }

  return KnowledgeBase.CreateKnowledgeBaseAction.form({
  });
});

function onFormSuccess() {
  emit('saved');
}
</script>

<template>
  <div class="mx-auto w-full max-w-none space-y-6">
    <HeadingSmall
      :title="title"
      :description="
        isEditMode
          ? ''
          : t('创建后可在知识库中上传文档或录入问答，为智能体提供检索能力。')
      "
    />

    <Form
      v-bind="formDef"
      :on-success="onFormSuccess"
      class="space-y-6"
      v-slot="{ errors, processing }"
    >
      <FormField :label="t('类别')">
        <div class="mt-1 rounded-md border px-3 py-2 text-sm">
          {{ props.categoryLabel }}
        </div>
        <input type="hidden" name="category" :value="props.category" />
      </FormField>

      <FormField
        :label="t('知识库名称')"
        label-for="kb-panel-name"
        :error="errors.name"
      >
        <Input
          id="kb-panel-name"
          name="name"
          class="mt-1 block w-full"
          :default-value="isEditMode ? knowledgeBase?.name : undefined"
          maxlength="120"
        />
      </FormField>

      <FormField
        :label="t('描述')"
        label-for="kb-panel-desc"
        :error="errors.description"
      >
        <Textarea
          id="kb-panel-desc"
          name="description"
          rows="3"
          :default-value="isEditMode ? (knowledgeBase?.description ?? '') : ''"
          class="mt-1 min-h-20"
        />
      </FormField>

      <ImageUploadField
        :label="t('知识库头像')"
        name="avatar_id"
        purpose="avatar"
        :initial-preview="
          isEditMode
            ? (props.knowledgeBase?.avatar_url ?? defaultKnowledgeBaseAvatar)
            : defaultKnowledgeBaseAvatar
        "
        :initial-value="
          isEditMode ? (props.knowledgeBase?.avatar_id ?? '') : ''
        "
        variant="logo"
        :error="errors.avatar_id"
      />

      <FormActions :submit-label="submitLabel" :processing="processing">
        <Button
          type="button"
          variant="outline"
          :disabled="processing"
          @click="emit('cancel')"
        >
          {{ t('取消') }}
        </Button>
      </FormActions>
    </Form>
  </div>
</template>
