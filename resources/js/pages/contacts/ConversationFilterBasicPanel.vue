<!--
  文件说明：会话记录基础筛选面板（状态 / 收件箱状态 / 分配 / 接待方案），
  作为统一筛选 Popover 内部的「基本」标签页内容。
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
import type {
  EnumOptionData,
  ReceptionPlanOptionData,
  UserOptionData,
} from '@/types/generated';

const { t } = useI18n();

defineProps<{
  status: string;
  inboxStatus: string;
  assignedUserId: string;
  receptionPlanId: string;
  statusOptions: EnumOptionData[];
  inboxStatusOptions: EnumOptionData[];
  teammateOptions: UserOptionData[];
  receptionPlanOptions: ReceptionPlanOptionData[];
}>();

defineEmits<{
  'update:status': [value: string];
  'update:inboxStatus': [value: string];
  'update:assignedUserId': [value: string];
  'update:receptionPlanId': [value: string];
}>();
</script>

<template>
  <div class="space-y-3 p-3">
    <div class="grid gap-2">
      <Label class="text-xs text-muted-foreground">{{ t('状态') }}</Label>
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

    <div class="grid gap-2">
      <Label class="text-xs text-muted-foreground">
        {{ t('收件箱状态') }}
      </Label>
      <Select
        :model-value="inboxStatus"
        @update:model-value="$emit('update:inboxStatus', String($event))"
      >
        <SelectTrigger class="h-9 w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">{{ t('全部收件箱状态') }}</SelectItem>
          <SelectItem
            v-for="option in inboxStatusOptions"
            :key="String(option.value)"
            :value="String(option.value)"
          >
            {{ option.label }}
          </SelectItem>
        </SelectContent>
      </Select>
    </div>

    <div class="grid gap-2">
      <Label class="text-xs text-muted-foreground">{{ t('分配给') }}</Label>
      <Select
        :model-value="assignedUserId"
        @update:model-value="$emit('update:assignedUserId', String($event))"
      >
        <SelectTrigger class="h-9 w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">{{ t('全部分配') }}</SelectItem>
          <SelectItem value="mine">{{ t('我负责的') }}</SelectItem>
          <SelectItem value="unassigned">{{ t('未分配') }}</SelectItem>
          <SelectItem
            v-for="teammate in teammateOptions"
            :key="teammate.id"
            :value="teammate.id"
          >
            {{ teammate.name }}
          </SelectItem>
        </SelectContent>
      </Select>
    </div>

    <div class="grid gap-2">
      <Label class="text-xs text-muted-foreground">{{ t('接待方案') }}</Label>
      <Select
        :model-value="receptionPlanId"
        @update:model-value="$emit('update:receptionPlanId', String($event))"
      >
        <SelectTrigger class="h-9 w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">{{ t('全部接待方案') }}</SelectItem>
          <SelectItem
            v-for="option in receptionPlanOptions"
            :key="option.id"
            :value="option.id"
          >
            {{ option.name }}
          </SelectItem>
        </SelectContent>
      </Select>
    </div>
  </div>
</template>
