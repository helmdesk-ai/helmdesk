<!--
  文件说明：接待方案表单字段渲染器。
  - 完全受控：表单状态由父级 useForm 提供，所有字段双向绑定到 form
  - 父级通过 section 控制渲染基础信息 / 接待智能体 / 任务智能体中的一段
  - 不持有自己的提交按钮，字段变更由父级表单统一提交
-->
<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- form 是父级传入的 useForm reactive proxy，按 Inertia 约定允许子组件 mutate */
import InputError from '@/components/common/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type {
  AiModelOptionData,
  EnumOptionData,
  ReceptionPlanData,
  TranslationProviderOptionData,
} from '@/types/generated';
import type { InertiaForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowDown, ArrowUp, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

export type ReceptionModelCandidateDraft = {
  ai_model_id: string;
  priority: number;
};

export type MessageTranslationConfigDraft = {
  enabled: boolean;
  failure_mode: string;
  provider_id: string | null;
};

// 供应商 Select 的「不启用翻译」哨兵值（provider_id 为 null）
const TRANSLATION_PROVIDER_NONE = '__none__';

export type PlanBasicsFormShape = {
  name: string;
  description: string;
  persona_display_name: string;
  translation_config: MessageTranslationConfigDraft;
  persona_tone: string;
  global_instructions: string;
  reception_ai_model_id: string;
  reception_model_candidates: ReceptionModelCandidateDraft[];
  task_ai_model_id: string;
  task_model_candidates: ReceptionModelCandidateDraft[];
};

export type PlanBasicsFormSection = 'basic' | 'reception' | 'task';

const props = defineProps<{
  /**
   * 父级 useForm 实例；本组件只关心 PlanBasicsFormShape 中的字段。
   * 父级可以基于 PlanBasicsFormShape 扩展更多字段（如 capabilities），不影响本组件渲染。
   */
  form: InertiaForm<PlanBasicsFormShape>;
  llmModelOptions: AiModelOptionData[];
  personaToneOptions: EnumOptionData[];
  messageTranslationFailureModeOptions: EnumOptionData[];
  translationProviderOptions: TranslationProviderOptionData[];
  plan: ReceptionPlanData;
  section: PlanBasicsFormSection;
}>();

const { t } = useI18n();

const selectedFailureModeDescription = computed(() => {
  const option = props.messageTranslationFailureModeOptions.find(
    (o) => String(o.value) === props.form.translation_config.failure_mode,
  );
  return option?.description ?? null;
});

// 把 provider_id（null 表示不翻译）映射到 Select 可用的字符串值；清空供应商时同步关闭访客侧翻译
const selectedTranslationProviderId = computed<string>({
  get: () =>
    props.form.translation_config.provider_id ?? TRANSLATION_PROVIDER_NONE,
  set: (value) => {
    if (value === TRANSLATION_PROVIDER_NONE) {
      props.form.translation_config.provider_id = null;
      props.form.translation_config.enabled = false;
      return;
    }
    props.form.translation_config.provider_id = value;
  },
});

const hasTranslationProvider = computed(
  () => props.form.translation_config.provider_id !== null,
);

const selectedReceptionModelId = computed<string>({
  get: () => props.form.reception_ai_model_id,
  set: (value) => {
    props.form.reception_ai_model_id = value;
    normalizeReceptionModelCandidates();
  },
});

const selectedTaskModelId = computed<string>({
  get: () => props.form.task_ai_model_id,
  set: (value) => {
    props.form.task_ai_model_id = value;
    normalizeTaskModelCandidates();
  },
});

const hasAvailableModels = computed(() => props.llmModelOptions.length > 0);
const hasAvailableReceptionBackupModels = computed(() =>
  props.llmModelOptions.some(
    (option) =>
      option.value !== props.form.reception_ai_model_id &&
      !props.form.reception_model_candidates.some(
        (candidate) => candidate.ai_model_id === option.value,
      ),
  ),
);
const hasAvailableTaskBackupModels = computed(() =>
  props.llmModelOptions.some(
    (option) =>
      option.value !== props.form.task_ai_model_id &&
      !props.form.task_model_candidates.some(
        (candidate) => candidate.ai_model_id === option.value,
      ),
  ),
);

const showReceptionModelInvalid = computed(
  () => !props.plan.reception_model_status.is_valid,
);
const showTaskModelInvalid = computed(
  () =>
    Boolean(props.plan.task_model_status) &&
    props.plan.task_model_status?.is_valid === false,
);

const groupedModelOptions = computed(() => {
  const groups = new Map<string, AiModelOptionData[]>();
  for (const option of props.llmModelOptions) {
    const list = groups.get(option.provider_name) ?? [];
    list.push(option);
    groups.set(option.provider_name, list);
  }

  return Array.from(groups, ([providerName, options]) => ({
    providerName,
    options,
  }));
});

function normalizeReceptionModelCandidates(): void {
  const seen = new Set<string>([props.form.reception_ai_model_id]);
  const normalized: ReceptionModelCandidateDraft[] = [];

  for (const candidate of props.form.reception_model_candidates) {
    if (candidate.ai_model_id === '' || seen.has(candidate.ai_model_id)) {
      continue;
    }

    seen.add(candidate.ai_model_id);
    normalized.push({
      ai_model_id: candidate.ai_model_id,
      priority: normalized.length + 1,
    });
  }

  props.form.reception_model_candidates = normalized;
}

function addReceptionBackupModel(): void {
  const option = props.llmModelOptions.find(
    (item) =>
      item.value !== props.form.reception_ai_model_id &&
      !props.form.reception_model_candidates.some(
        (candidate) => candidate.ai_model_id === item.value,
      ),
  );

  if (!option) {
    return;
  }

  props.form.reception_model_candidates = [
    ...props.form.reception_model_candidates,
    {
      ai_model_id: option.value,
      priority: props.form.reception_model_candidates.length + 1,
    },
  ];
}

function normalizeTaskModelCandidates(): void {
  const seen = new Set<string>([props.form.task_ai_model_id]);
  const normalized: ReceptionModelCandidateDraft[] = [];

  for (const candidate of props.form.task_model_candidates) {
    if (candidate.ai_model_id === '' || seen.has(candidate.ai_model_id)) {
      continue;
    }

    seen.add(candidate.ai_model_id);
    normalized.push({
      ai_model_id: candidate.ai_model_id,
      priority: normalized.length + 1,
    });
  }

  props.form.task_model_candidates = normalized;
}

function addTaskBackupModel(): void {
  const option = props.llmModelOptions.find(
    (item) =>
      item.value !== props.form.task_ai_model_id &&
      !props.form.task_model_candidates.some(
        (candidate) => candidate.ai_model_id === item.value,
      ),
  );

  if (!option) {
    return;
  }

  props.form.task_model_candidates = [
    ...props.form.task_model_candidates,
    {
      ai_model_id: option.value,
      priority: props.form.task_model_candidates.length + 1,
    },
  ];
}

function removeReceptionBackupModel(index: number): void {
  props.form.reception_model_candidates = props.form.reception_model_candidates
    .filter((_, candidateIndex) => candidateIndex !== index)
    .map((candidate, candidateIndex) => ({
      ...candidate,
      priority: candidateIndex + 1,
    }));
}

function removeTaskBackupModel(index: number): void {
  props.form.task_model_candidates = props.form.task_model_candidates
    .filter((_, candidateIndex) => candidateIndex !== index)
    .map((candidate, candidateIndex) => ({
      ...candidate,
      priority: candidateIndex + 1,
    }));
}

function moveReceptionBackupModel(index: number, direction: -1 | 1): void {
  const nextIndex = index + direction;
  if (
    nextIndex < 0 ||
    nextIndex >= props.form.reception_model_candidates.length
  ) {
    return;
  }

  const candidates = [...props.form.reception_model_candidates];
  const current = candidates[index];
  candidates[index] = candidates[nextIndex];
  candidates[nextIndex] = current;

  props.form.reception_model_candidates = candidates.map(
    (candidate, candidateIndex) => ({
      ...candidate,
      priority: candidateIndex + 1,
    }),
  );
}

function moveTaskBackupModel(index: number, direction: -1 | 1): void {
  const nextIndex = index + direction;
  if (nextIndex < 0 || nextIndex >= props.form.task_model_candidates.length) {
    return;
  }

  const candidates = [...props.form.task_model_candidates];
  const current = candidates[index];
  candidates[index] = candidates[nextIndex];
  candidates[nextIndex] = current;

  props.form.task_model_candidates = candidates.map(
    (candidate, candidateIndex) => ({
      ...candidate,
      priority: candidateIndex + 1,
    }),
  );
}

function updateReceptionBackupModel(index: number, modelId: string): void {
  props.form.reception_model_candidates =
    props.form.reception_model_candidates.map((candidate, candidateIndex) =>
      candidateIndex === index
        ? {
            ai_model_id: modelId,
            priority: candidate.priority,
          }
        : candidate,
    );
  normalizeReceptionModelCandidates();
}

function updateTaskBackupModel(index: number, modelId: string): void {
  props.form.task_model_candidates = props.form.task_model_candidates.map(
    (candidate, candidateIndex) =>
      candidateIndex === index
        ? {
            ai_model_id: modelId,
            priority: candidate.priority,
          }
        : candidate,
  );
  normalizeTaskModelCandidates();
}

function isReceptionModelOptionUnavailableForCandidate(
  modelId: string,
  candidateIndex: number,
): boolean {
  if (modelId === props.form.reception_ai_model_id) {
    return true;
  }

  return props.form.reception_model_candidates.some(
    (candidate, index) =>
      index !== candidateIndex && candidate.ai_model_id === modelId,
  );
}

function isTaskModelOptionUnavailableForCandidate(
  modelId: string,
  candidateIndex: number,
): boolean {
  if (modelId === props.form.task_ai_model_id) {
    return true;
  }

  return props.form.task_model_candidates.some(
    (candidate, index) =>
      index !== candidateIndex && candidate.ai_model_id === modelId,
  );
}

function receptionCandidateError(index: number): string | undefined {
  const errors = props.form.errors as Record<string, string | undefined>;
  return (
    errors[`reception_model_candidates.${index}.ai_model_id`] ??
    errors[`reception_model_candidates.${index}.priority`]
  );
}

function taskCandidateError(index: number): string | undefined {
  const errors = props.form.errors as Record<string, string | undefined>;
  return (
    errors[`task_model_candidates.${index}.ai_model_id`] ??
    errors[`task_model_candidates.${index}.priority`]
  );
}

function translationConfigError(
  field: keyof MessageTranslationConfigDraft,
): string | undefined {
  const errors = props.form.errors as Record<string, string | undefined>;
  return errors[`translation_config.${field}`];
}
</script>

<template>
  <div class="space-y-5">
    <Alert
      v-if="props.section === 'reception' && showReceptionModelInvalid"
      variant="destructive"
    >
      <AlertTriangle class="h-4 w-4" />
      <AlertDescription>
        {{ t('当前接待智能体模型不可用，请重新选择。') }}
      </AlertDescription>
    </Alert>
    <Alert
      v-if="props.section === 'task' && showTaskModelInvalid"
      variant="destructive"
    >
      <AlertTriangle class="h-4 w-4" />
      <AlertDescription>
        {{ t('当前任务智能体默认模型不可用，请重新选择。') }}
      </AlertDescription>
    </Alert>

    <div v-if="props.section === 'basic'" class="grid gap-4">
      <div class="grid gap-2">
        <Label for="plan_basics_name" required>{{ t('方案名称') }}</Label>
        <Input id="plan_basics_name" v-model="props.form.name" required />
        <InputError :message="props.form.errors.name" />
      </div>

      <div class="grid gap-2">
        <Label for="plan_basics_description">{{ t('方案简介') }}</Label>
        <Textarea
          id="plan_basics_description"
          v-model="props.form.description"
          rows="4"
          class="min-h-24"
        />
        <InputError :message="props.form.errors.description" />
      </div>

      <div class="grid gap-2">
        <Label for="plan_basics_persona_display_name" required>
          {{ t('对外昵称') }}
        </Label>
        <Input
          id="plan_basics_persona_display_name"
          v-model="props.form.persona_display_name"
          required
        />
        <InputError :message="props.form.errors.persona_display_name" />
      </div>

      <div class="grid gap-3 pt-1">
        <div class="grid gap-2">
          <Label for="plan_basics_message_translation_provider">
            {{ t('翻译供应商') }}
          </Label>
          <Select v-model="selectedTranslationProviderId">
            <SelectTrigger
              id="plan_basics_message_translation_provider"
              class="w-full"
            >
              <SelectValue :placeholder="t('不启用翻译')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem :value="TRANSLATION_PROVIDER_NONE">
                {{ t('不启用翻译') }}
              </SelectItem>
              <SelectItem
                v-for="option in props.translationProviderOptions"
                :key="option.id"
                :value="option.id"
                :disabled="!option.has_complete_credentials"
              >
                {{ option.name }}
                <span v-if="!option.has_complete_credentials">
                  （{{ t('凭据未配置完整') }}）
                </span>
              </SelectItem>
            </SelectContent>
          </Select>
          <p class="text-xs text-muted-foreground">
            {{ t('选择该方案用于消息翻译的供应商；不选则该方案不翻译。') }}
          </p>
          <InputError :message="translationConfigError('provider_id')" />
        </div>

        <div class="flex items-center justify-between gap-4">
          <div class="grid gap-1">
            <Label for="plan_basics_message_translation_enabled">
              {{ t('访客侧文案自动翻译') }}
            </Label>
            <p class="text-xs text-muted-foreground">
              {{
                t(
                  '开启后，接待方案内需要发送给访客的预设文案会按访客语言发送。',
                )
              }}
            </p>
          </div>
          <Switch
            id="plan_basics_message_translation_enabled"
            v-model="props.form.translation_config.enabled"
            :disabled="!hasTranslationProvider"
          />
        </div>
        <InputError :message="translationConfigError('enabled')" />

        <div v-if="props.form.translation_config.enabled" class="grid gap-2">
          <Label for="plan_basics_message_translation_failure_mode" required>
            {{ t('访客侧文案翻译失败时') }}
          </Label>
          <Select
            v-model="props.form.translation_config.failure_mode"
            :disabled="!props.form.translation_config.enabled"
          >
            <SelectTrigger
              id="plan_basics_message_translation_failure_mode"
              class="w-full"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in props.messageTranslationFailureModeOptions"
                :key="option.value"
                :value="String(option.value)"
              >
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
          <p
            v-if="selectedFailureModeDescription"
            class="text-xs text-muted-foreground"
          >
            {{ selectedFailureModeDescription }}
          </p>
          <InputError :message="translationConfigError('failure_mode')" />
        </div>
      </div>
    </div>

    <div v-else-if="props.section === 'reception'" class="grid gap-4">
      <div class="grid gap-2">
        <Label for="plan_basics_persona_tone" required>
          {{ t('语气风格') }}
        </Label>
        <Select v-model="props.form.persona_tone">
          <SelectTrigger id="plan_basics_persona_tone" class="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in props.personaToneOptions"
              :key="option.value"
              :value="String(option.value)"
            >
              {{ option.label }}
            </SelectItem>
          </SelectContent>
        </Select>
        <InputError :message="props.form.errors.persona_tone" />
      </div>

      <div class="grid gap-2">
        <Label for="plan_basics_global_instructions">
          {{ t('接待指引') }}
        </Label>
        <Textarea
          id="plan_basics_global_instructions"
          v-model="props.form.global_instructions"
          rows="6"
          class="min-h-32"
        />
        <InputError :message="props.form.errors.global_instructions" />
      </div>

      <div class="grid gap-2">
        <Label for="plan_basics_reception_ai_model_id" required>
          {{ t('默认模型') }}
        </Label>
        <Select
          v-model="selectedReceptionModelId"
          :disabled="!hasAvailableModels"
        >
          <SelectTrigger id="plan_basics_reception_ai_model_id" class="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup
              v-for="group in groupedModelOptions"
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
        <InputError :message="props.form.errors.reception_ai_model_id" />
      </div>

      <div class="grid gap-3">
        <div class="flex items-center justify-between gap-3">
          <Label>{{ t('备用模型') }}</Label>
          <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="!hasAvailableReceptionBackupModels"
            @click="addReceptionBackupModel"
          >
            {{ t('添加') }}
          </Button>
        </div>

        <div
          v-if="props.form.reception_model_candidates.length > 0"
          class="space-y-2"
        >
          <div
            v-for="(candidate, idx) in props.form.reception_model_candidates"
            :key="`${candidate.priority}-${candidate.ai_model_id}-${idx}`"
            class="grid gap-2 rounded-md border p-3"
          >
            <div class="flex items-center gap-2">
              <Badge variant="outline" class="shrink-0">
                {{ t('优先级 {priority}', { priority: candidate.priority }) }}
              </Badge>
              <Select
                :model-value="candidate.ai_model_id"
                @update:model-value="
                  (value) => updateReceptionBackupModel(idx, String(value))
                "
              >
                <SelectTrigger class="min-w-0 flex-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup
                    v-for="group in groupedModelOptions"
                    :key="group.providerName"
                  >
                    <SelectLabel>{{ group.providerName }}</SelectLabel>
                    <SelectItem
                      v-for="option in group.options"
                      :key="option.value"
                      :value="option.value"
                      :disabled="
                        isReceptionModelOptionUnavailableForCandidate(
                          option.value,
                          idx,
                        )
                      "
                    >
                      {{ option.label }}
                    </SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="idx === 0"
                :aria-label="t('上移')"
                @click="moveReceptionBackupModel(idx, -1)"
              >
                <ArrowUp class="h-4 w-4" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="
                  idx === props.form.reception_model_candidates.length - 1
                "
                :aria-label="t('下移')"
                @click="moveReceptionBackupModel(idx, 1)"
              >
                <ArrowDown class="h-4 w-4" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                class="text-destructive hover:text-destructive"
                :aria-label="t('删除')"
                @click="removeReceptionBackupModel(idx)"
              >
                <Trash2 class="h-4 w-4" />
              </Button>
            </div>
            <InputError :message="receptionCandidateError(idx)" />
          </div>
        </div>

        <p v-else class="text-xs text-muted-foreground">
          {{ t('未配置备用模型。') }}
        </p>
      </div>
    </div>

    <div v-else class="grid gap-4">
      <div class="grid gap-2">
        <Label for="plan_basics_task_ai_model_id" required>
          {{ t('默认模型') }}
        </Label>
        <Select v-model="selectedTaskModelId" :disabled="!hasAvailableModels">
          <SelectTrigger id="plan_basics_task_ai_model_id" class="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup
              v-for="group in groupedModelOptions"
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
        <InputError :message="props.form.errors.task_ai_model_id" />
      </div>

      <div class="grid gap-3">
        <div class="flex items-center justify-between gap-3">
          <Label>{{ t('备用模型') }}</Label>
          <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="!hasAvailableTaskBackupModels"
            @click="addTaskBackupModel"
          >
            {{ t('添加') }}
          </Button>
        </div>

        <div
          v-if="props.form.task_model_candidates.length > 0"
          class="space-y-2"
        >
          <div
            v-for="(candidate, idx) in props.form.task_model_candidates"
            :key="`${candidate.priority}-${candidate.ai_model_id}-${idx}`"
            class="grid gap-2 rounded-md border p-3"
          >
            <div class="flex items-center gap-2">
              <Badge variant="outline" class="shrink-0">
                {{ t('优先级 {priority}', { priority: candidate.priority }) }}
              </Badge>
              <Select
                :model-value="candidate.ai_model_id"
                @update:model-value="
                  (value) => updateTaskBackupModel(idx, String(value))
                "
              >
                <SelectTrigger class="min-w-0 flex-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup
                    v-for="group in groupedModelOptions"
                    :key="group.providerName"
                  >
                    <SelectLabel>{{ group.providerName }}</SelectLabel>
                    <SelectItem
                      v-for="option in group.options"
                      :key="option.value"
                      :value="option.value"
                      :disabled="
                        isTaskModelOptionUnavailableForCandidate(
                          option.value,
                          idx,
                        )
                      "
                    >
                      {{ option.label }}
                    </SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="idx === 0"
                :aria-label="t('上移')"
                @click="moveTaskBackupModel(idx, -1)"
              >
                <ArrowUp class="h-4 w-4" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="idx === props.form.task_model_candidates.length - 1"
                :aria-label="t('下移')"
                @click="moveTaskBackupModel(idx, 1)"
              >
                <ArrowDown class="h-4 w-4" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                class="text-destructive hover:text-destructive"
                :aria-label="t('删除')"
                @click="removeTaskBackupModel(idx)"
              >
                <Trash2 class="h-4 w-4" />
              </Button>
            </div>
            <InputError :message="taskCandidateError(idx)" />
          </div>
        </div>

        <p v-else class="text-xs text-muted-foreground">
          {{ t('未配置备用模型。') }}
        </p>
      </div>
    </div>
  </div>
</template>
