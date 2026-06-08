<!--
  AI 模型创建/编辑表单（独立页，对齐供应商交互）。一行 = 一个模型 + 一个用途。
  创建：选供应商 + 用途 + model_id + 名称，model_id 可点「预设模型」从该品牌常见模型里挑选填充。
  编辑：供应商 / 用途 / model_id 只读，仅改名称与启用。提交到 admin.manage.ai.models.store / update。
-->
<script setup lang="ts">
import AiModel from '@/actions/App/Actions/AiModel';
import FormActions from '@/components/common/FormActions.vue';
import FormField from '@/components/common/FormField.vue';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
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
import { Switch } from '@/components/ui/switch';
import { useI18n } from '@/composables/useI18n';
import type {
  AiModelListItemData,
  AiModelPurpose,
  AiProviderOptionData,
  CatalogModelOptionData,
  EnumOptionData,
} from '@/types/generated';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// 用途 → 能力类型，决定「预设模型」按品牌候选里筛哪一类。
const PURPOSE_TYPE: Record<AiModelPurpose, string> = {
  reception_chat: 'llm',
  background_task: 'llm',
  assistant: 'llm',
  summary: 'llm',
  embedding: 'embedding',
  rerank: 'rerank',
};

type ModelForm = {
  ai_provider_id: string;
  purpose: string;
  model_id: string;
  name: string;
  is_active: boolean;
};

const props = defineProps<{
  mode: 'create' | 'edit';
  model?: AiModelListItemData | null;
  providerOptions?: AiProviderOptionData[];
  purposeOptions?: EnumOptionData[];
  defaultModelsByBrand?: Record<string, CatalogModelOptionData[]>;
}>();

const { t } = useI18n();

const isEditMode = computed(() => props.mode === 'edit');
const providerOptions = computed(() => props.providerOptions ?? []);
const purposeOptions = computed(() => props.purposeOptions ?? []);

const form = useForm<ModelForm>({
  ai_provider_id:
    props.model?.ai_provider_id ?? providerOptions.value[0]?.id ?? '',
  purpose:
    props.model?.purpose ?? purposeOptions.value[0]?.value?.toString() ?? '',
  model_id: props.model?.model_id ?? '',
  name: props.model?.name ?? '',
  is_active: props.model?.is_active ?? true,
});

const selectedBrand = computed<string | null>(
  () =>
    providerOptions.value.find((p) => p.id === form.ai_provider_id)?.brand ??
    null,
);

const presetModels = computed<CatalogModelOptionData[]>(() => {
  if (!selectedBrand.value) {
    return [];
  }

  const type = PURPOSE_TYPE[form.purpose as AiModelPurpose] ?? 'llm';
  return (props.defaultModelsByBrand?.[selectedBrand.value] ?? []).filter(
    (m) => m.type === type,
  );
});

const presetOpen = ref(false);

function pickPreset(model: CatalogModelOptionData): void {
  form.model_id = model.model_id;
  presetOpen.value = false;
}

function submit(): void {
  if (isEditMode.value && props.model) {
    form
      .transform((data) => ({ name: data.name, is_active: data.is_active }))
      .put(AiModel.UpdateAiModelAction.url({ model: props.model.id }), {
        preserveScroll: true,
      });
    return;
  }

  form
    .transform((data) => ({
      ai_provider_id: data.ai_provider_id,
      purpose: data.purpose,
      model_id: data.model_id.trim(),
      name: data.name.trim(),
    }))
    .post(AiModel.CreateAiModelAction.url(), { preserveScroll: true });
}
</script>

<template>
  <form class="space-y-6" @submit.prevent="submit">
    <FormField
      v-if="!isEditMode"
      :label="t('供应商')"
      :error="form.errors.ai_provider_id"
      required
    >
      <Select
        :model-value="form.ai_provider_id"
        @update:model-value="(value) => (form.ai_provider_id = String(value))"
      >
        <SelectTrigger class="mt-1 w-full">
          <SelectValue :placeholder="t('选择供应商')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem
            v-for="option in providerOptions"
            :key="option.id"
            :value="option.id"
          >
            {{ option.name }}
          </SelectItem>
        </SelectContent>
      </Select>
    </FormField>

    <FormField v-else :label="t('供应商')">
      <div class="mt-1 rounded-md border px-3 py-2 text-sm">
        {{ props.model?.provider_name }}
      </div>
    </FormField>

    <FormField
      v-if="!isEditMode"
      :label="t('用途')"
      :error="form.errors.purpose"
      required
    >
      <Select
        :model-value="form.purpose"
        @update:model-value="(value) => (form.purpose = String(value))"
      >
        <SelectTrigger class="mt-1 w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem
            v-for="option in purposeOptions"
            :key="String(option.value)"
            :value="String(option.value)"
          >
            {{ option.label }}
          </SelectItem>
        </SelectContent>
      </Select>
    </FormField>

    <FormField v-else :label="t('用途')">
      <div class="mt-1 rounded-md border px-3 py-2 text-sm">
        {{ props.model?.purpose_label }}
      </div>
    </FormField>

    <div class="grid gap-2">
      <div class="flex items-center gap-2">
        <Label for="ai-model-id" required>{{ t('模型 ID') }}</Label>
        <button
          v-if="!isEditMode && presetModels.length > 0"
          type="button"
          class="text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
          @click="presetOpen = true"
        >
          {{ t('预设模型') }}
        </button>
      </div>
      <Input
        v-if="!isEditMode"
        id="ai-model-id"
        v-model="form.model_id"
        autocomplete="off"
        class="block w-full"
        required
      />
      <Input
        v-else
        :model-value="props.model?.model_id"
        disabled
        readonly
        class="block w-full cursor-not-allowed font-mono"
      />
      <p v-if="form.errors.model_id" class="text-sm text-destructive">
        {{ form.errors.model_id }}
      </p>
    </div>

    <FormField
      :label="t('显示名称')"
      label-for="ai-model-name"
      :error="form.errors.name"
      required
    >
      <Input
        id="ai-model-name"
        v-model="form.name"
        autocomplete="off"
        class="mt-1 block w-full"
        required
      />
    </FormField>

    <div v-if="isEditMode" class="grid gap-2">
      <Label for="ai-model-active">{{ t('启用') }}</Label>
      <Switch id="ai-model-active" v-model="form.is_active" />
    </div>

    <FormActions
      :submit-label="isEditMode ? t('保存') : t('创建')"
      :processing="form.processing"
      :cancel-href="AiModel.ShowAiModelListAction.url()"
      :cancel-label="t('返回')"
    />
  </form>

  <Dialog v-model:open="presetOpen">
    <DialogContent class="max-h-[80vh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle>{{ t('预设模型') }}</DialogTitle>
        <DialogDescription>
          {{ t('选择一个预设模型以填充模型 ID。') }}
        </DialogDescription>
      </DialogHeader>
      <div class="space-y-2">
        <button
          v-for="option in presetModels"
          :key="option.model_id"
          type="button"
          class="flex w-full flex-col gap-1 rounded-md border px-3 py-2 text-left hover:bg-muted/50"
          @click="pickPreset(option)"
        >
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ option.name }}</span>
            <Badge variant="secondary" class="font-mono text-[10px]">
              {{ option.model_id }}
            </Badge>
          </div>
          <span v-if="option.description" class="text-xs text-muted-foreground">
            {{ option.description }}
          </span>
        </button>
      </div>
    </DialogContent>
  </Dialog>
</template>
