<!--
  问答条目创建/编辑表单面板，内嵌在问答知识库列表右侧区域，
  支持标准问题、相似问法、多答案的添加与编辑，保存后触发标准索引流水线。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import FormActions from '@/components/common/FormActions.vue';
import FormField from '@/components/common/FormField.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import type { ListKnowledgeQaEntryItemData } from '@/types/generated';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

type Mode = 'create' | 'edit';

const props = defineProps<{
  mode: Mode;
  knowledgeBaseId: string;
  groupOptions: Array<{ id: string; label: string }>;
  defaultGroupId: string | null;
  entry: ListKnowledgeQaEntryItemData | null;
}>();

const emit = defineEmits<{
  cancel: [];
  saved: [];
}>();

const { t } = useI18n();
const currentWorkspace = useRequiredWorkspace();

const form = useForm<{
  question: string;
  similar_questions: string[];
  answers: string[];
  group_id: string;
}>({
  question: '',
  similar_questions: [],
  answers: [''],
  group_id: '',
});

const initialFormSnapshot = ref('');

onMounted(initializeFormForCurrentTarget);

function snapshotForm(): string {
  return JSON.stringify({
    question: form.question,
    similar_questions: form.similar_questions,
    answers: form.answers,
    group_id: form.group_id,
  });
}

function snapshotInitial(): void {
  initialFormSnapshot.value = snapshotForm();
}

function initializeFormForCurrentTarget(): void {
  form.clearErrors();

  if (props.mode === 'create') {
    form.question = '';
    form.similar_questions = [];
    form.answers = [''];
    form.group_id = props.defaultGroupId ?? props.groupOptions[0]?.id ?? '';
    snapshotInitial();
    return;
  }

  const target = props.entry;
  if (!target) {
    return;
  }

  form.question = target.question;
  form.similar_questions = [...target.similar_questions];
  form.answers = target.answers.length > 0 ? [...target.answers] : [''];
  form.group_id = target.group_id;
  snapshotInitial();
}

const isDirty = computed(() => snapshotForm() !== initialFormSnapshot.value);

const normalizedAnswers = computed(() =>
  form.answers.map((answer) => answer.trim()).filter((answer) => answer !== ''),
);

const submitDisabled = computed(
  () =>
    form.processing ||
    form.question.trim() === '' ||
    normalizedAnswers.value.length === 0,
);

function addSimilarQuestion(): void {
  form.similar_questions = [...form.similar_questions, ''];
}

function removeSimilarQuestion(index: number): void {
  form.similar_questions = form.similar_questions.filter((_, i) => i !== index);
}

function addAnswer(): void {
  form.answers = [...form.answers, ''];
}

function removeAnswer(index: number): void {
  if (form.answers.length === 1) {
    form.answers = [''];
    return;
  }
  form.answers = form.answers.filter((_, i) => i !== index);
}

function confirmDiscardIfDirty(): boolean {
  if (!isDirty.value) {
    return true;
  }
  return window.confirm(
    t('已编辑的内容尚未保存，确定要关闭吗？关闭后修改将丢失。'),
  );
}

function submit(): void {
  if (submitDisabled.value) {
    return;
  }

  const payload = (data: {
    question: string;
    similar_questions: string[];
    answers: string[];
    group_id: string;
  }) => ({
    question: data.question,
    similar_questions: data.similar_questions
      .map((question) => question.trim())
      .filter((question) => question !== ''),
    answers: data.answers
      .map((answer) => answer.trim())
      .filter((answer) => answer !== ''),
    group_id: data.group_id || null,
  });

  if (props.mode === 'create') {
    form.transform(payload).post(
      KnowledgeBase.Qa.CreateKnowledgeQaEntryAction.url({
        slug: currentWorkspace.value.slug,
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

  const target = props.entry;
  if (!target) {
    return;
  }

  form.transform(payload).put(
    KnowledgeBase.Qa.UpdateKnowledgeQaEntryAction.url({
      slug: currentWorkspace.value.slug,
      knowledgeBase: props.knowledgeBaseId,
      entry: target.id,
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
    <HeadingSmall :title="mode === 'create' ? t('添加问答') : t('编辑问答')" />

    <form class="space-y-6" @submit.prevent="submit">
      <FormField
        :label="t('标准问题')"
        label-for="qa-entry-question"
        :error="form.errors.question"
      >
        <Input
          id="qa-entry-question"
          v-model="form.question"
          class="mt-1 block w-full"
          type="text"
          autocomplete="off"
          :aria-invalid="Boolean(form.errors.question)"
        />
      </FormField>

      <FormField
        v-if="mode === 'create' && groupOptions.length > 0"
        :label="t('分组')"
        label-for="qa-entry-group"
        :error="form.errors.group_id"
      >
        <Select v-model="form.group_id">
          <SelectTrigger id="qa-entry-group" class="mt-1 w-full">
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

      <FormField :label="t('相似问法')" :error="form.errors.similar_questions">
        <div class="mt-1 flex justify-end">
          <Button
            type="button"
            variant="outline"
            size="sm"
            @click="addSimilarQuestion"
          >
            <Plus class="mr-1.5 h-4 w-4" />
            {{ t('添加') }}
          </Button>
        </div>
        <div
          v-if="form.similar_questions.length === 0"
          class="mt-2 text-sm text-muted-foreground"
        >
          {{ t('暂无相似问法') }}
        </div>
        <div
          v-for="(_, index) in form.similar_questions"
          :key="index"
          class="mt-2 flex items-center gap-2"
        >
          <Input
            v-model="form.similar_questions[index]"
            type="text"
            autocomplete="off"
          />
          <Button
            type="button"
            variant="ghost"
            size="icon"
            :aria-label="t('删除')"
            @click="removeSimilarQuestion(index)"
          >
            <Trash2 class="h-4 w-4" />
          </Button>
        </div>
      </FormField>

      <FormField :label="t('答案')" :error="form.errors.answers">
        <div class="mt-1 flex justify-end">
          <Button type="button" variant="outline" size="sm" @click="addAnswer">
            <Plus class="mr-1.5 h-4 w-4" />
            {{ t('添加') }}
          </Button>
        </div>
        <div
          v-for="(_, index) in form.answers"
          :key="index"
          class="mt-2 space-y-2 rounded-md border p-3"
        >
          <div class="flex items-center justify-between gap-3">
            <span class="text-sm font-medium">
              {{ t('答案') }} {{ index + 1 }}
            </span>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              :aria-label="t('删除')"
              @click="removeAnswer(index)"
            >
              <Trash2 class="h-4 w-4" />
            </Button>
          </div>
          <Textarea
            v-model="form.answers[index]"
            class="min-h-32"
            :aria-invalid="Boolean(form.errors.answers)"
          />
        </div>
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
