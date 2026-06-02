<!--
  知识库手动文档创建/编辑面板，内嵌在知识库列表右侧区域，
  支持 Markdown 格式的文档标题、正文编辑，编辑时会异步加载已有文档内容。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import FormActions from '@/components/common/FormActions.vue';
import FormField from '@/components/common/FormField.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import MarkdownEditor from '@/components/common/MarkdownEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import type { ListKnowledgeDocumentItemData } from '@/types/generated';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';

type Mode = 'create' | 'edit';

const props = defineProps<{
  mode: Mode;
  knowledgeBaseId: string;
  groupOptions: Array<{ id: string; label: string }>;
  defaultGroupId: string | null;
  document: ListKnowledgeDocumentItemData | null;
}>();

const emit = defineEmits<{
  cancel: [];
  saved: [];
}>();

const { t } = useI18n();

const form = useForm<{
  title: string;
  content: string;
  group_id: string;
}>({
  title: '',
  content: '',
  group_id: '',
});

const loadingContent = ref(false);
const contentLoadError = ref<string | null>(null);
const initialFormSnapshot = ref<{
  title: string;
  content: string;
  group_id: string;
}>({
  title: '',
  content: '',
  group_id: '',
});

onMounted(initializeFormForCurrentTarget);

function snapshotInitial(): void {
  initialFormSnapshot.value = {
    title: form.title,
    content: form.content,
    group_id: form.group_id,
  };
}

function initializeFormForCurrentTarget(): void {
  form.clearErrors();
  contentLoadError.value = null;

  if (props.mode === 'create') {
    form.title = '';
    form.content = '';
    form.group_id = props.defaultGroupId ?? props.groupOptions[0]?.id ?? '';
    loadingContent.value = false;
    snapshotInitial();
    return;
  }

  const target = props.document;
  if (!target) {
    return;
  }

  form.title = target.original_filename;
  form.content = '';
  form.group_id = target.group_id;
  snapshotInitial();
  void loadContentForEdit(target);
}

async function loadContentForEdit(
  target: ListKnowledgeDocumentItemData,
): Promise<void> {
  loadingContent.value = true;
  try {
    const url =
      KnowledgeBase.Document.StreamKnowledgeDocumentPreviewFileAction.url({
        knowledgeBase: props.knowledgeBaseId,
        document: target.id,
      });
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`Failed to load document content: ${response.status}`);
    }
    form.content = await response.text();
    snapshotInitial();
  } catch {
    contentLoadError.value = t('文档预览加载失败');
  } finally {
    loadingContent.value = false;
  }
}

const isDirty = computed(() => {
  if (loadingContent.value) {
    return false;
  }
  return (
    form.title !== initialFormSnapshot.value.title ||
    form.content !== initialFormSnapshot.value.content ||
    form.group_id !== initialFormSnapshot.value.group_id
  );
});

function confirmDiscardIfDirty(): boolean {
  if (!isDirty.value) {
    return true;
  }
  return window.confirm(
    t('已编辑的内容尚未保存，确定要关闭吗？关闭后修改将丢失。'),
  );
}

const submitDisabled = computed(
  () =>
    form.processing ||
    loadingContent.value ||
    form.title.trim() === '' ||
    form.content.trim() === '',
);

function submit(): void {
  if (submitDisabled.value) {
    return;
  }

  if (props.mode === 'create') {
    form
      .transform((data) => ({
        title: data.title,
        content: data.content,
        group_id: data.group_id || null,
      }))
      .post(
        KnowledgeBase.Document.CreateManualKnowledgeDocumentAction.url({
          knowledgeBase: props.knowledgeBaseId,
        }),
        {
          preserveScroll: true,
          onSuccess: () => {
            emit('saved');
          },
        },
      );
    return;
  }

  const target = props.document;
  if (!target) {
    return;
  }

  form
    .transform((data) => ({
      title: data.title,
      content: data.content,
    }))
    .put(
      KnowledgeBase.Document.UpdateManualKnowledgeDocumentAction.url({
        knowledgeBase: props.knowledgeBaseId,
        document: target.id,
      }),
      {
        preserveScroll: true,
        onSuccess: () => {
          emit('saved');
        },
      },
    );
}

function close(): void {
  if (form.processing) {
    return;
  }
  if (!confirmDiscardIfDirty()) {
    return;
  }
  emit('cancel');
}
</script>

<template>
  <div class="mx-auto w-full max-w-none space-y-6">
    <HeadingSmall
      :title="mode === 'create' ? t('手动添加文档') : t('编辑文档')"
    />

    <form class="space-y-6" @submit.prevent="submit">
      <FormField
        :label="t('标题')"
        label-for="manual-document-title"
        :error="form.errors.title"
      >
        <Input
          id="manual-document-title"
          v-model="form.title"
          class="mt-1 block w-full"
          type="text"
          autocomplete="off"
          :aria-invalid="Boolean(form.errors.title)"
        />
      </FormField>

      <FormField
        v-if="mode === 'create' && groupOptions.length > 0"
        :label="t('分组')"
        label-for="manual-document-group"
        :error="form.errors.group_id"
      >
        <Select v-model="form.group_id">
          <SelectTrigger id="manual-document-group" class="mt-1 w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="group in groupOptions"
              :key="group.id"
              :value="group.id"
            >
              {{ group.label }}
            </SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <FormField
        :label="t('正文')"
        :error="contentLoadError ?? form.errors.content"
      >
        <div
          v-if="loadingContent"
          class="mt-1 flex h-72 items-center justify-center rounded-md border border-dashed"
        >
          <Spinner class="h-5 w-5" />
        </div>
        <MarkdownEditor
          v-else
          v-model="form.content"
          :height="420"
          :disabled="form.processing"
        />
      </FormField>

      <FormActions
        :submit-label="form.processing ? t('保存中...') : t('保存')"
        :processing="form.processing"
        :submit-disabled="submitDisabled"
      >
        <template #submit>
          <LoaderCircle
            v-if="form.processing"
            class="mr-1.5 h-4 w-4 animate-spin"
          />
          {{ form.processing ? t('保存中...') : t('保存') }}
        </template>
        <Button
          type="button"
          variant="outline"
          :disabled="form.processing"
          @click="close"
        >
          {{ t('取消') }}
        </Button>
      </FormActions>
    </form>
  </div>
</template>
