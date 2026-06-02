<script setup lang="ts">
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';

const { t } = useI18n();

interface Option {
  code: string;
  label: string;
}

const props = defineProps<{
  modelValue: Option[];
  disabled?: boolean;
  errors?: string;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: Option[]];
}>();

const options = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val),
});

const duplicateCodes = computed(() => {
  const codes = options.value.map((o) => o.code).filter(Boolean);
  const seen = new Set<string>();
  const dupes = new Set<string>();
  for (const c of codes) {
    if (seen.has(c)) {
      dupes.add(c);
    }
    seen.add(c);
  }
  return dupes;
});

const addOption = () => {
  options.value = [...options.value, { code: '', label: '' }];
};

const removeOption = (index: number) => {
  if (options.value.length <= 1) {
    return;
  }
  options.value = options.value.filter((_, i) => i !== index);
};

const updateOption = (
  index: number,
  field: 'code' | 'label',
  value: string,
) => {
  const updated = [...options.value];
  updated[index] = { ...updated[index], [field]: value };
  options.value = updated;
};
</script>

<template>
  <div class="space-y-3">
    <Label>{{ t('选项管理') }}</Label>

    <div class="space-y-2">
      <div
        v-for="(option, index) in options"
        :key="index"
        class="flex items-start gap-2"
      >
        <div class="flex-1 space-y-1">
          <Input
            :model-value="option.code"
            :disabled="props.disabled"
            :class="{ 'border-destructive': duplicateCodes.has(option.code) }"
            @update:model-value="
              (v: string | number) => updateOption(index, 'code', String(v))
            "
          />
        </div>
        <div class="flex-1 space-y-1">
          <Input
            :model-value="option.label"
            :disabled="props.disabled"
            @update:model-value="
              (v: string | number) => updateOption(index, 'label', String(v))
            "
          />
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          class="shrink-0"
          :disabled="props.disabled || options.length <= 1"
          @click="removeOption(index)"
        >
          <Trash2 class="h-4 w-4 text-muted-foreground" />
        </Button>
      </div>
    </div>

    <Button
      type="button"
      variant="outline"
      size="sm"
      :disabled="props.disabled"
      @click="addOption"
    >
      <Plus class="mr-1 h-4 w-4" />
      {{ t('添加选项') }}
    </Button>

    <InputError :message="props.errors" />
  </div>
</template>
