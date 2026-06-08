<!--
  文件说明：接待方案表单字段渲染器。
  - 完全受控：表单状态由父级 useForm 提供，所有字段双向绑定到 form
  - 父级通过 section 控制渲染基础信息 / 接待智能体 / 任务智能体中的一段
  - 不持有自己的提交按钮，字段变更由父级表单统一提交
  - 模型不再由方案选择，运行时按用途从全局池取用，本组件不含模型选择 UI
-->
<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- form 是父级传入的 useForm reactive proxy，按 Inertia 约定允许子组件 mutate */
import InputError from '@/components/common/InputError.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type {
  EnumOptionData,
  TranslationProviderOptionData,
} from '@/types/generated';
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';

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
};

export type PlanBasicsFormSection = 'basic' | 'reception' | 'task';

const props = defineProps<{
  /**
   * 父级 useForm 实例；本组件只关心 PlanBasicsFormShape 中的字段。
   * 父级可以基于 PlanBasicsFormShape 扩展更多字段（如 capabilities），不影响本组件渲染。
   */
  form: InertiaForm<PlanBasicsFormShape>;
  personaToneOptions: EnumOptionData[];
  messageTranslationFailureModeOptions: EnumOptionData[];
  translationProviderOptions: TranslationProviderOptionData[];
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

function translationConfigError(
  field: keyof MessageTranslationConfigDraft,
): string | undefined {
  const errors = props.form.errors as Record<string, string | undefined>;
  return errors[`translation_config.${field}`];
}
</script>

<template>
  <div class="space-y-5">
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

      <p class="text-sm text-muted-foreground">
        {{ t('模型由总后台按用途统一配置，运行时自动取用，无需在此选择。') }}
      </p>
    </div>

    <div v-else class="grid gap-4">
      <p class="text-sm text-muted-foreground">
        {{
          t(
            '任务智能体的模型由总后台按用途统一配置，运行时自动取用，无需在此选择。',
          )
        }}
      </p>
    </div>
  </div>
</template>
