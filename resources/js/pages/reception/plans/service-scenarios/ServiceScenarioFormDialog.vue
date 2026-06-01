<!--
  服务场景创建 / 编辑表单，嵌入 Dialog 内使用。
-->
<script setup lang="ts">
import FormField from '@/components/common/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type { ServiceScenarioTemplateData } from '@/types/generated';
import { computed, ref, watch } from 'vue';

/** 服务场景草稿，与父级 form.service_scenarios 数组中的元素同形。 */
export type ServiceScenarioDraft = {
  name: string;
  description: string;
  instructions: string;
};

const props = defineProps<{
  mode: 'create' | 'edit';
  /** 编辑模式必传；创建模式忽略。 */
  scenario?: ServiceScenarioDraft | null;
  /** 创建模式下用于"使用模板"预填；编辑模式忽略。 */
  initialTemplate?: ServiceScenarioTemplateData | null;
}>();

const emit = defineEmits<{
  cancel: [];
  save: [draft: ServiceScenarioDraft];
}>();

const { t } = useI18n();

const formName = ref<string>('');
const formDescription = ref<string>('');
const formInstructions = ref<string>('');

const localErrors = ref<{
  name?: string;
  instructions?: string;
}>({});

watch(
  () => [props.mode, props.scenario, props.initialTemplate],
  () => {
    localErrors.value = {};

    if (props.mode === 'edit' && props.scenario) {
      formName.value = props.scenario.name;
      formDescription.value = props.scenario.description;
      formInstructions.value = props.scenario.instructions;
      return;
    }

    const template = props.initialTemplate ?? null;
    formName.value = template?.name ?? '';
    formDescription.value = template?.description ?? '';
    formInstructions.value = template?.instructions ?? '';
  },
  { immediate: true, deep: true },
);

const submitLabel = computed(() =>
  props.mode === 'edit' ? t('保存场景') : t('添加'),
);

function validateLocally(): boolean {
  const errors: typeof localErrors.value = {};
  const name = formName.value.trim();
  const instructions = formInstructions.value.trim();

  if (name === '') {
    errors.name = t('场景名称不能为空');
  }

  if (instructions === '') {
    errors.instructions = t('场景指令不能为空');
  }

  localErrors.value = errors;
  return Object.keys(errors).length === 0;
}

function handleSubmit(): void {
  if (!validateLocally()) {
    return;
  }

  emit('save', {
    name: formName.value.trim(),
    description: formDescription.value.trim(),
    instructions: formInstructions.value,
  });
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="handleSubmit">
    <FormField
      :label="t('场景名称')"
      label-for="scenario_form_name"
      :error="localErrors.name"
      required
    >
      <Input id="scenario_form_name" v-model="formName" class="mt-1" />
    </FormField>

    <FormField :label="t('场景简介')" label-for="scenario_form_description">
      <Textarea
        id="scenario_form_description"
        v-model="formDescription"
        rows="2"
        class="mt-1 min-h-16"
      />
    </FormField>

    <FormField
      :label="t('场景指令')"
      label-for="scenario_form_instructions"
      :error="localErrors.instructions"
      required
    >
      <Textarea
        id="scenario_form_instructions"
        v-model="formInstructions"
        rows="8"
        class="mt-1 min-h-36 font-mono text-xs"
      />
    </FormField>

    <div class="flex justify-end gap-2 pt-2">
      <Button type="button" variant="outline" @click="emit('cancel')">
        {{ t('取消') }}
      </Button>
      <Button type="submit">{{ submitLabel }}</Button>
    </div>
  </form>
</template>
