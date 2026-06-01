<!--
  文件说明：会话记录页的回复状态筛选面板，消费 ShowConversationListPagePropsData 的 visitor_reply_status_options。
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
  visitorReplyStatus: string;
  visitorReplyStatusOptions: EnumOptionData[];
}>();

defineEmits<{
  'update:visitorReplyStatus': [value: string];
}>();
</script>

<template>
  <div class="space-y-3 p-3">
    <div class="grid gap-2">
      <Label class="text-xs text-muted-foreground">
        {{ t('回复状态') }}
      </Label>
      <Select
        :model-value="visitorReplyStatus"
        @update:model-value="$emit('update:visitorReplyStatus', String($event))"
      >
        <SelectTrigger class="h-9 w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">{{ t('全部回复状态') }}</SelectItem>
          <SelectItem
            v-for="option in visitorReplyStatusOptions"
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
