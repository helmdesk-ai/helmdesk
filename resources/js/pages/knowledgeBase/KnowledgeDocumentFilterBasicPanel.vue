<!--
  文件说明：知识库文档基础筛选面板，用于文档列表右上角筛选 Popover。
-->
<script setup lang="ts">
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import type { EnumOptionData } from '@/types/generated';

const { t } = useI18n();

defineProps<{
  status: string;
  statusOptions: EnumOptionData[];
}>();

defineEmits<{
  'update:status': [value: string];
}>();
</script>

<template>
  <div class="space-y-3 p-3">
    <div class="grid gap-2">
      <Label>{{ t('状态') }}</Label>
      <Select
        :model-value="status"
        @update:model-value="$emit('update:status', String($event))"
      >
        <SelectTrigger class="h-9 w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">{{ t('全部状态') }}</SelectItem>
          <SelectItem
            v-for="option in statusOptions"
            :key="String(option.value)"
            :value="String(option.value)"
          >
            {{ option.label }}
          </SelectItem>
        </SelectContent>
      </Select>
    </div>
  </div>
</template>
