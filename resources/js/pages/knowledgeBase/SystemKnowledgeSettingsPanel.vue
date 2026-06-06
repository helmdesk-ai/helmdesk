<!--
  系统知识库检索配置面板，内嵌在知识库列表右侧区域，供有权限的用户统一配置嵌入模型、分段策略、
  深度索引和重排序模型等检索参数，保存时若配置变更会触发索引重建确认。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import FormActions from '@/components/common/FormActions.vue';
import FormField from '@/components/common/FormField.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
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
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useI18n } from '@/composables/useI18n';
import type {
  AiModelOptionData,
  EnumOptionData,
  KnowledgeChunkingStrategy,
  SystemKnowledgeSettingsData,
} from '@/types/generated';
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
  settings: SystemKnowledgeSettingsData;
  embeddingModelOptions: AiModelOptionData[];
  rerankModelOptions: AiModelOptionData[];
  summaryModelOptions: AiModelOptionData[];
  chunkingStrategyOptions: EnumOptionData[];
}>();

const { t } = useI18n();

const noEmbeddingModelValue = '__none__';
const noRerankModelValue = '__none__';
const noSummaryModelValue = '__none__';

const selectedEmbeddingModelId = ref(noEmbeddingModelValue);
const embeddingDimension = ref<number | null>(null);
const selectedRerankModelId = ref(noRerankModelValue);
const selectedSummaryModelId = ref(noSummaryModelValue);
const vectorIndexEnabled = ref(false);
const raptorIndexEnabled = ref(false);
const selectedChunkingStrategy = ref<KnowledgeChunkingStrategy>('fixed');
const chunkMaxTokens = ref(512);
const chunkOverlapTokens = ref(64);
const rebuildWarningOpen = ref(false);
const acknowledgedSignature = ref<string | null>(null);

const formDef = computed(() =>
  KnowledgeBase.UpdateSystemKnowledgeSettingsAction.form({}),
);

const submittedEmbeddingModelId = computed(() =>
  selectedEmbeddingModelId.value === noEmbeddingModelValue
    ? ''
    : selectedEmbeddingModelId.value,
);

const submittedRerankModelId = computed(() =>
  selectedRerankModelId.value === noRerankModelValue
    ? ''
    : selectedRerankModelId.value,
);

const submittedSummaryModelId = computed(() =>
  selectedSummaryModelId.value === noSummaryModelValue
    ? ''
    : selectedSummaryModelId.value,
);

const submittedEmbeddingDimension = computed(() =>
  embeddingDimension.value !== null &&
  Number.isFinite(Number(embeddingDimension.value))
    ? String(Math.trunc(Number(embeddingDimension.value)))
    : '',
);

const currentSignature = computed(() =>
  settingsSignature({
    vectorIndexEnabled: vectorIndexEnabled.value,
    embeddingModelId: submittedEmbeddingModelId.value,
    embeddingDimension: submittedEmbeddingDimension.value,
    raptorIndexEnabled: raptorIndexEnabled.value,
    summaryModelId: submittedSummaryModelId.value,
    chunkingStrategy: selectedChunkingStrategy.value,
    chunkMaxTokens: Number(chunkMaxTokens.value),
    chunkOverlapTokens: Number(chunkOverlapTokens.value),
  }),
);

const initialSignature = computed(() =>
  settingsSignature({
    vectorIndexEnabled: Boolean(props.settings.vector_index_enabled),
    embeddingModelId: props.settings.embedding_model_id ?? '',
    embeddingDimension:
      props.settings.embedding_dimension !== null &&
      props.settings.embedding_dimension !== undefined
        ? String(props.settings.embedding_dimension)
        : '',
    raptorIndexEnabled: Boolean(props.settings.raptor_index_enabled),
    summaryModelId: props.settings.summary_model_id ?? '',
    chunkingStrategy: props.settings.chunking_strategy,
    chunkMaxTokens: props.settings.chunk_max_tokens ?? 512,
    chunkOverlapTokens: props.settings.chunk_overlap_tokens ?? 64,
  }),
);

const settingsChanged = computed(
  () => currentSignature.value !== initialSignature.value,
);

const groupedEmbeddingOptions = computed(() =>
  groupModelOptions(props.embeddingModelOptions),
);

const groupedRerankOptions = computed(() =>
  groupModelOptions(props.rerankModelOptions),
);

const groupedSummaryOptions = computed(() =>
  groupModelOptions(props.summaryModelOptions),
);

watch(() => props.settings, initForm, { immediate: true });

function initForm() {
  acknowledgedSignature.value = null;
  selectedEmbeddingModelId.value =
    props.settings.embedding_model_id ?? noEmbeddingModelValue;
  embeddingDimension.value =
    props.settings.embedding_dimension !== null &&
    props.settings.embedding_dimension !== undefined
      ? Number(props.settings.embedding_dimension)
      : null;
  selectedRerankModelId.value =
    props.settings.rerank_model_id ?? noRerankModelValue;
  selectedSummaryModelId.value =
    props.settings.summary_model_id ?? noSummaryModelValue;
  vectorIndexEnabled.value = Boolean(props.settings.vector_index_enabled);
  raptorIndexEnabled.value = Boolean(props.settings.raptor_index_enabled);
  selectedChunkingStrategy.value = props.settings.chunking_strategy;
  chunkMaxTokens.value = props.settings.chunk_max_tokens ?? 512;
  chunkOverlapTokens.value = props.settings.chunk_overlap_tokens ?? 64;
}

function settingsSignature(config: {
  vectorIndexEnabled: boolean;
  embeddingModelId: string;
  embeddingDimension: string;
  raptorIndexEnabled: boolean;
  summaryModelId: string;
  chunkingStrategy: KnowledgeChunkingStrategy;
  chunkMaxTokens: number;
  chunkOverlapTokens: number;
}): string {
  return JSON.stringify(config);
}

function groupModelOptions(options: AiModelOptionData[]) {
  const groups = new Map<string, AiModelOptionData[]>();

  for (const option of options) {
    const list = groups.get(option.provider_name) ?? [];
    list.push(option);
    groups.set(option.provider_name, list);
  }

  return Array.from(groups, ([providerName, groupedOptions]) => ({
    providerName,
    options: groupedOptions,
  }));
}

function handleBeforeSubmit(): boolean {
  if (!settingsChanged.value) {
    acknowledgedSignature.value = null;
    return true;
  }

  if (acknowledgedSignature.value !== currentSignature.value) {
    rebuildWarningOpen.value = true;
    return false;
  }

  return true;
}

function confirmSettingsChange(submit: () => void) {
  acknowledgedSignature.value = currentSignature.value;
  rebuildWarningOpen.value = false;
  submit();
}

function onFormSuccess() {
  acknowledgedSignature.value = null;
}
</script>

<template>
  <div class="mx-auto w-full max-w-none space-y-6">
    <HeadingSmall
      :title="t('知识库检索配置')"
      :description="t('系统内所有知识库共用这套检索配置。')"
    />

    <Form
      v-bind="formDef"
      :on-before="handleBeforeSubmit"
      :on-success="onFormSuccess"
      class="space-y-6"
      v-slot="{ errors, processing, submit }"
    >
      <div class="flex items-start justify-between gap-4">
        <div class="space-y-1">
          <Label for="system-vector-enabled">{{ t('标准索引') }}</Label>
          <p class="text-sm text-muted-foreground">
            {{ t('为文档建立基础索引，用于日常知识库问答。') }}
          </p>
          <InputError class="mt-2" :message="errors.vector_index_enabled" />
        </div>
        <Switch
          id="system-vector-enabled"
          :model-value="vectorIndexEnabled"
          :aria-label="t('标准索引')"
          @update:model-value="
            (checked) => (vectorIndexEnabled = Boolean(checked))
          "
        />
        <input
          type="hidden"
          name="vector_index_enabled"
          :value="vectorIndexEnabled ? '1' : '0'"
        />
      </div>

      <FormField
        v-if="vectorIndexEnabled"
        :label="t('嵌入模型')"
        label-for="system-embedding-model"
        :error="errors.embedding_model_id"
      >
        <Select v-model="selectedEmbeddingModelId">
          <SelectTrigger id="system-embedding-model" class="mt-1 w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="noEmbeddingModelValue">
              {{ t('请选择嵌入模型') }}
            </SelectItem>
            <SelectGroup
              v-for="group in groupedEmbeddingOptions"
              :key="group.providerName"
            >
              <SelectLabel>{{ group.providerName }}</SelectLabel>
              <SelectItem
                v-for="option in group.options"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </SelectItem>
            </SelectGroup>
          </SelectContent>
        </Select>
        <input
          type="hidden"
          name="embedding_model_id"
          :value="submittedEmbeddingModelId"
        />
      </FormField>

      <FormField
        v-if="vectorIndexEnabled"
        :label="t('向量维度')"
        label-for="system-embedding-dimension"
        :error="errors.embedding_dimension"
      >
        <Input
          id="system-embedding-dimension"
          class="mt-1 block w-full"
          type="number"
          min="1"
          max="65535"
          step="1"
          v-model="embeddingDimension"
        />
        <input
          type="hidden"
          name="embedding_dimension"
          :value="submittedEmbeddingDimension"
        />
      </FormField>

      <template v-else>
        <input
          type="hidden"
          name="embedding_dimension"
          :value="submittedEmbeddingDimension"
        />
      </template>

      <FormField
        v-if="vectorIndexEnabled"
        :label="t('分段方式')"
        label-for="system-chunking-strategy"
      >
        <Select v-model="selectedChunkingStrategy">
          <SelectTrigger id="system-chunking-strategy" class="mt-1 w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in props.chunkingStrategyOptions"
              :key="option.value"
              :value="String(option.value)"
            >
              {{ option.label }}
            </SelectItem>
          </SelectContent>
        </Select>
        <input
          type="hidden"
          name="chunking_strategy"
          :value="selectedChunkingStrategy"
        />
      </FormField>

      <template v-else>
        <input
          type="hidden"
          name="chunking_strategy"
          :value="selectedChunkingStrategy"
        />
      </template>

      <FormField
        v-if="vectorIndexEnabled"
        :label="t('单段最大 token')"
        label-for="system-chunk-max"
        :error="errors.chunk_max_tokens"
      >
        <Input
          id="system-chunk-max"
          name="chunk_max_tokens"
          class="mt-1 block w-full"
          type="number"
          min="64"
          max="4096"
          v-model="chunkMaxTokens"
        />
      </FormField>

      <FormField
        v-if="vectorIndexEnabled"
        :label="t('相邻段重叠 token')"
        label-for="system-chunk-overlap"
        :error="errors.chunk_overlap_tokens"
      >
        <Input
          id="system-chunk-overlap"
          name="chunk_overlap_tokens"
          class="mt-1 block w-full"
          type="number"
          min="0"
          max="2048"
          v-model="chunkOverlapTokens"
        />
      </FormField>

      <template v-else>
        <input type="hidden" name="chunk_max_tokens" :value="chunkMaxTokens" />
        <input
          type="hidden"
          name="chunk_overlap_tokens"
          :value="chunkOverlapTokens"
        />
      </template>

      <div class="flex items-start justify-between gap-4">
        <div class="space-y-1">
          <Label for="system-raptor-enabled">{{ t('深度索引') }}</Label>
          <p class="text-sm text-muted-foreground">
            {{ t('为长文档建立更深入的层级索引，提升复杂问题的命中效果。') }}
          </p>
          <InputError class="mt-2" :message="errors.raptor_index_enabled" />
        </div>
        <Switch
          id="system-raptor-enabled"
          :model-value="raptorIndexEnabled"
          :aria-label="t('深度索引')"
          @update:model-value="
            (checked) => (raptorIndexEnabled = Boolean(checked))
          "
        />
        <input
          type="hidden"
          name="raptor_index_enabled"
          :value="raptorIndexEnabled ? '1' : '0'"
        />
      </div>

      <FormField
        v-if="raptorIndexEnabled"
        :label="t('摘要模型')"
        label-for="system-summary-model"
        :error="errors.summary_model_id"
      >
        <Select v-model="selectedSummaryModelId">
          <SelectTrigger id="system-summary-model" class="mt-1 w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="noSummaryModelValue">
              {{ t('请选择摘要模型') }}
            </SelectItem>
            <SelectGroup
              v-for="group in groupedSummaryOptions"
              :key="group.providerName"
            >
              <SelectLabel>{{ group.providerName }}</SelectLabel>
              <SelectItem
                v-for="option in group.options"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </SelectItem>
            </SelectGroup>
          </SelectContent>
        </Select>
        <input
          type="hidden"
          name="summary_model_id"
          :value="submittedSummaryModelId"
        />
      </FormField>

      <FormField
        :label="t('重排序模型（可选）')"
        label-for="system-rerank-model"
        :error="errors.rerank_model_id"
      >
        <Select v-model="selectedRerankModelId">
          <SelectTrigger id="system-rerank-model" class="mt-1 w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem :value="noRerankModelValue">
              {{ t('不使用重排序') }}
            </SelectItem>
            <SelectGroup
              v-for="group in groupedRerankOptions"
              :key="group.providerName"
            >
              <SelectLabel>{{ group.providerName }}</SelectLabel>
              <SelectItem
                v-for="option in group.options"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </SelectItem>
            </SelectGroup>
          </SelectContent>
        </Select>
        <input
          type="hidden"
          name="rerank_model_id"
          :value="submittedRerankModelId"
        />
      </FormField>

      <FormActions :submit-label="t('保存')" :processing="processing" />

      <Dialog
        :open="rebuildWarningOpen"
        @update:open="rebuildWarningOpen = $event"
      >
        <DialogContent class="sm:max-w-md">
          <DialogHeader class="space-y-3">
            <DialogTitle>{{ t('确认更新检索配置') }}</DialogTitle>
            <DialogDescription>
              {{ t('保存后会清理系统已有知识库索引，并按新的配置重新构建。') }}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter class="gap-2">
            <Button
              type="button"
              variant="secondary"
              @click="rebuildWarningOpen = false"
            >
              {{ t('取消') }}
            </Button>
            <Button type="button" @click="confirmSettingsChange(submit)">
              {{ t('继续保存') }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Form>
  </div>
</template>
