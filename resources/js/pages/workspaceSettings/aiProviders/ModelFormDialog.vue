<!--
  文件说明：工作区 AI 模型弹窗，用于在指定供应商下新增或调整模型基础信息。
-->
<script setup lang="ts">
import AiProvider from '@/actions/App/Actions/AiProvider';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import type { AiModelData } from '@/types/generated';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, watch } from 'vue';

type ModelFormState = {
  model_id: string;
  name: string;
  type: string;
};

const props = defineProps<{
  open: boolean;
  model?: AiModelData | null;
  providerName: string;
  providerSlug: string;
}>();

const emit = defineEmits<{
  'update:open': [value: boolean];
}>();

const { t } = useI18n();

const defaultModelFormState = (): ModelFormState => ({
  model_id: '',
  name: '',
  type: 'llm',
});

const form = useForm<ModelFormState>(defaultModelFormState());

const isEdit = computed(() => !!props.model);

const dialogTitle = computed(() => {
  if (!isEdit.value) {
    return t('添加模型');
  }

  return t('编辑模型');
});

const isFormValid = computed(() => {
  return (
    form.model_id.trim() !== '' && form.name.trim() !== '' && form.type !== ''
  );
});

const syncForm = (model: AiModelData | null | undefined): void => {
  form.clearErrors();

  if (model) {
    form.model_id = model.model_id;
    form.name = model.name;
    form.type = model.type;

    return;
  }

  form.defaults(defaultModelFormState());
  form.reset();
};

watch(
  [() => props.model, () => props.open],
  ([model, open]) => {
    if (open) {
      syncForm(model);
    } else {
      form.clearErrors();
    }
  },
  { immediate: true },
);

const handleSave = () => {
  if (!isFormValid.value || form.processing || !props.providerSlug) {
    return;
  }

  form
    .transform((data) => ({
      ...data,
      model_id: data.model_id.trim(),
      name: data.name.trim(),
    }))
    .post(
      AiProvider.CreateAiModelAction.url({
        provider: props.providerSlug,
      }),
      {
        preserveScroll: true,
        onSuccess: () => {
          form.defaults(defaultModelFormState());
          form.reset();
          emit('update:open', false);
        },
      },
    );
};
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
      <DialogHeader>
        <DialogTitle>{{ dialogTitle }}</DialogTitle>
        <DialogDescription>{{ providerName }}</DialogDescription>
      </DialogHeader>

      <form class="space-y-5" @submit.prevent="handleSave">
        <div class="space-y-4">
          <div class="grid gap-2">
            <Label>{{ t('模型类型') }}</Label>
            <Select v-model="form.type" :disabled="isEdit">
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="llm">{{ t('大语言模型') }}</SelectItem>
                <SelectItem value="embedding">{{ t('嵌入模型') }}</SelectItem>
                <SelectItem value="rerank">{{ t('重排序模型') }}</SelectItem>
              </SelectContent>
            </Select>
            <InputError :message="form.errors.type" />
          </div>

          <div class="grid gap-2">
            <Label>{{ t('模型 ID') }}</Label>
            <Input
              v-model="form.model_id"
              :disabled="isEdit"
              autocomplete="off"
            />
            <InputError :message="form.errors.model_id" />
          </div>

          <div class="grid gap-2">
            <Label>{{ t('显示名称') }}</Label>
            <Input v-model="form.name" autocomplete="off" />
            <InputError :message="form.errors.name" />
          </div>
        </div>

        <DialogFooter class="gap-2">
          <Button
            type="button"
            variant="secondary"
            :disabled="form.processing"
            @click="emit('update:open', false)"
          >
            {{ t('取消') }}
          </Button>
          <Button type="submit" :disabled="!isFormValid || form.processing">
            <LoaderCircle
              v-if="form.processing"
              class="mr-2 h-4 w-4 animate-spin"
            />
            {{ t('保存') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
