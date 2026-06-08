<!--
  创建接待方案表单面板，内嵌在接待方案页右侧主内容区。
-->
<script setup lang="ts">
import Plan from '@/actions/App/Actions/Reception/Plan';
import FormActions from '@/components/common/FormActions.vue';
import FormField from '@/components/common/FormField.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type { MessageTranslationConfigDraft } from '@/pages/reception/plans/PlanBasicsForm.vue';
import type { ReceptionStrategyConfigDraft } from '@/pages/reception/plans/PlanStrategyForm.vue';
import type { AiModelOptionData, EnumOptionData } from '@/types/generated';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
  llmModelOptions: AiModelOptionData[];
  personaToneOptions: EnumOptionData[];
}>();

const emit = defineEmits<{
  cancel: [];
  saved: [];
}>();

const { t } = useI18n();

const selectedReceptionModelId = ref<string>('');
const selectedTaskModelId = ref<string>('');

type CreatePlanForm = {
  name: string;
  description: string;
  persona_display_name: string;
  persona_tone: string;
  global_instructions: string | null;
  reception_ai_model_id: string;
  reception_model_candidates: Array<{ ai_model_id: string; priority: number }>;
  task_ai_model_id: string;
  task_model_candidates: Array<{ ai_model_id: string; priority: number }>;
  strategy_config: ReceptionStrategyConfigDraft;
  auto_messages_config: {
    ai_welcome: { enabled: boolean; message: string };
    teammate_joined: { enabled: boolean; message: string };
    teammate_transferred: { enabled: boolean; message: string };
  };
  translation_config: MessageTranslationConfigDraft;
};

function defaultModelId(): string {
  return props.llmModelOptions[0]?.value !== undefined
    ? String(props.llmModelOptions[0].value)
    : '';
}

function formDefaults(): CreatePlanForm {
  const modelId = defaultModelId();

  return {
    name: '',
    description: '',
    persona_display_name: '',
    persona_tone: 'concise',
    global_instructions: t(
      '请保持友好、简洁、准确；先理解访客问题，再给出可执行答复。不确定时说明限制并询问关键信息。',
    ),
    reception_ai_model_id: modelId,
    reception_model_candidates: [],
    task_ai_model_id: modelId,
    task_model_candidates: [],
    strategy_config: {
      reception_mode: 'ai_first',
      unassigned_ai_takeover_enabled: false,
      unassigned_ai_takeover_timeout_seconds: 120,
      teammate_no_response_ai_takeover_enabled: true,
      teammate_no_response_ai_takeover_timeout_seconds: 300,
      important_contact_ai_careful_reply_enabled: true,
      important_contact_ai_handoff_hint_enabled: true,
      important_contact_human_first_when_online_enabled: false,
      quote_visitor_message_enabled: false,
      handoff_available_notice: t('已为您转接人工客服，请稍等。'),
      handoff_no_teammate_notice: t('当前暂无法转接人工，我会继续为您处理。'),
      ai_unavailable_notice: t(
        '很抱歉，AI 助手暂时无法为您服务，正在为您转接人工客服，请稍候。',
      ),
      business_hours: null,
    },
    auto_messages_config: {
      ai_welcome: {
        enabled: true,
        message: t('您好，我是{{display_name}}，请问有什么可以帮您？'),
      },
      teammate_joined: {
        enabled: true,
        message: t('您好，我是{{teammate_name}}，接下来由我为您服务。'),
      },
      teammate_transferred: {
        enabled: true,
        message: t('您好，我是{{teammate_name}}，已接手本次会话。'),
      },
    },
    translation_config: {
      enabled: false,
      failure_mode: 'skip',
    },
  };
}

const form = useForm<CreatePlanForm>(formDefaults());

watch(
  () => props.llmModelOptions,
  () => {
    const defaults = formDefaults();
    form.defaults(defaults);
    form.reset();
    form.clearErrors();
    selectedReceptionModelId.value = defaults.reception_ai_model_id;
    selectedTaskModelId.value = defaults.task_ai_model_id;
  },
  { immediate: true },
);

watch(selectedReceptionModelId, (modelId) => {
  form.reception_ai_model_id = modelId;
});

watch(selectedTaskModelId, (modelId) => {
  form.task_ai_model_id = modelId;
});

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

const hasAvailableModels = computed(() => props.llmModelOptions.length > 0);

function submit(): void {
  form.post(Plan.CreateReceptionPlanAction.url(), {
    preserveScroll: true,
    onSuccess: () => emit('saved'),
  });
}
</script>

<template>
  <div class="mx-auto w-full max-w-none space-y-6">
    <HeadingSmall
      :title="t('添加接待方案')"
      :description="t('先添加方案，再继续完善人设、服务场景等详细配置。')"
    />

    <form class="space-y-6" @submit.prevent="submit">
      <FormField
        :label="t('方案名称')"
        label-for="create_plan_name"
        :error="form.errors.name"
        required
      >
        <Input
          id="create_plan_name"
          v-model="form.name"
          class="mt-1 block w-full"
          autocomplete="off"
          required
        />
      </FormField>

      <FormField
        :label="t('方案简介')"
        label-for="create_plan_description"
        :error="form.errors.description"
      >
        <Textarea
          id="create_plan_description"
          v-model="form.description"
          rows="3"
          class="mt-1 min-h-20"
        />
      </FormField>

      <FormField
        :label="t('对外昵称')"
        label-for="create_plan_persona_display_name"
        :error="form.errors.persona_display_name"
        required
      >
        <Input
          id="create_plan_persona_display_name"
          v-model="form.persona_display_name"
          class="mt-1 block w-full"
          autocomplete="off"
          required
        />
      </FormField>

      <FormField
        :label="t('语气风格')"
        label-for="create_plan_persona_tone"
        :error="form.errors.persona_tone"
        required
      >
        <Select v-model="form.persona_tone">
          <SelectTrigger id="create_plan_persona_tone" class="mt-1 w-full">
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
      </FormField>

      <FormField
        :label="t('接待智能体模型')"
        label-for="create_plan_reception_model"
        :error="form.errors.reception_ai_model_id"
        required
      >
        <Select
          v-model="selectedReceptionModelId"
          :disabled="!hasAvailableModels"
        >
          <SelectTrigger id="create_plan_reception_model" class="mt-1 w-full">
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
      </FormField>

      <FormField
        :label="t('任务智能体默认模型')"
        label-for="create_plan_task_model"
        :error="form.errors.task_ai_model_id"
        required
      >
        <Select v-model="selectedTaskModelId" :disabled="!hasAvailableModels">
          <SelectTrigger id="create_plan_task_model" class="mt-1 w-full">
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
      </FormField>

      <FormActions
        :submit-label="t('添加')"
        :processing="form.processing"
        :submit-disabled="!hasAvailableModels"
      >
        <template #submit>
          <LoaderCircle
            v-if="form.processing"
            class="mr-2 h-4 w-4 animate-spin"
          />
          {{ t('添加') }}
        </template>

        <Button
          type="button"
          variant="outline"
          :disabled="form.processing"
          @click="emit('cancel')"
        >
          {{ t('取消') }}
        </Button>
      </FormActions>
    </form>
  </div>
</template>
