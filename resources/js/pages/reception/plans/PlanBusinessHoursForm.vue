<!--
  文件说明：接待方案营业时间表单字段。
-->
<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- form 是父级 useForm reactive proxy，按 Inertia 约定允许子组件 mutate */
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
  PlanStrategyFormShape,
  ReceptionBusinessHoursDayDraft,
  ReceptionBusinessHoursDraft,
} from '@/pages/reception/plans/PlanStrategyForm.vue';
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
  form: InertiaForm<PlanStrategyFormShape>;
}>();

const { t } = useI18n();

const days = [
  { day: 1, label: t('周一') },
  { day: 2, label: t('周二') },
  { day: 3, label: t('周三') },
  { day: 4, label: t('周四') },
  { day: 5, label: t('周五') },
  { day: 6, label: t('周六') },
  { day: 7, label: t('周日') },
];

const commonTimezones = [
  { value: 'Asia/Shanghai', label: t('中国标准时间 (UTC+8)') },
  { value: 'Asia/Tokyo', label: t('日本标准时间 (UTC+9)') },
  { value: 'Asia/Singapore', label: t('新加坡时间 (UTC+8)') },
  { value: 'Asia/Seoul', label: t('韩国标准时间 (UTC+9)') },
  { value: 'Asia/Hong_Kong', label: t('香港时间 (UTC+8)') },
  { value: 'Asia/Kolkata', label: t('印度标准时间 (UTC+5:30)') },
  { value: 'Europe/London', label: t('英国时间 (UTC+0/+1)') },
  { value: 'Europe/Paris', label: t('中欧时间 (UTC+1/+2)') },
  { value: 'Europe/Berlin', label: t('德国时间 (UTC+1/+2)') },
  { value: 'America/New_York', label: t('美国东部时间 (UTC-5/-4)') },
  { value: 'America/Chicago', label: t('美国中部时间 (UTC-6/-5)') },
  { value: 'America/Los_Angeles', label: t('美国太平洋时间 (UTC-8/-7)') },
  { value: 'UTC', label: t('协调世界时 (UTC+0)') },
];

const businessHoursEnabled = computed<boolean>({
  get: () => props.form.strategy_config.business_hours !== null,
  set: (enabled) => {
    props.form.strategy_config.business_hours = enabled
      ? (props.form.strategy_config.business_hours ?? defaultBusinessHours())
      : null;
  },
});

function defaultSchedule(): ReceptionBusinessHoursDayDraft[] {
  return days.map(({ day }) => ({
    day,
    enabled: day <= 5,
    open: '09:00',
    close: '18:00',
  }));
}

function defaultBusinessHours(): ReceptionBusinessHoursDraft {
  return {
    timezone: 'Asia/Shanghai',
    outside_hours_notice: t(
      '当前为非人工服务时间，我会先为您处理；如需客服，将在人工服务时间内继续跟进。',
    ),
    schedule: defaultSchedule(),
  };
}

function strategyError(field: string): string | undefined {
  return (props.form.errors as Record<string, string | undefined>)[
    `strategy_config.${field}`
  ];
}

function updateScheduleDay(
  day: number,
  field: keyof ReceptionBusinessHoursDayDraft,
  value: boolean | string,
): void {
  const hours = props.form.strategy_config.business_hours;
  if (!hours) {
    return;
  }

  const index = hours.schedule.findIndex((item) => item.day === day);
  if (index === -1) {
    return;
  }

  hours.schedule[index] = { ...hours.schedule[index], [field]: value };
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
      <div class="space-y-1">
        <Label>{{ t('启用人工服务时间') }}</Label>
        <InputError :message="strategyError('business_hours')" />
      </div>
      <Switch v-model="businessHoursEnabled" />
    </div>

    <template v-if="props.form.strategy_config.business_hours">
      <div class="grid gap-2">
        <Label for="plan_business_hours_timezone" required>
          {{ t('时区') }}
        </Label>
        <Select v-model="props.form.strategy_config.business_hours.timezone">
          <SelectTrigger id="plan_business_hours_timezone" class="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="tz in commonTimezones"
              :key="tz.value"
              :value="tz.value"
            >
              {{ tz.label }}
            </SelectItem>
          </SelectContent>
        </Select>
        <InputError :message="strategyError('business_hours.timezone')" />
      </div>

      <div class="grid gap-2">
        <Label>{{ t('每周可用时段') }}</Label>
        <div class="divide-y divide-border rounded-md border">
          <div
            v-for="dayMeta in days"
            :key="dayMeta.day"
            class="flex flex-wrap items-center gap-4 px-4 py-3"
          >
            <Switch
              :model-value="
                props.form.strategy_config.business_hours.schedule.find(
                  (day) => day.day === dayMeta.day,
                )?.enabled ?? false
              "
              @update:model-value="
                updateScheduleDay(dayMeta.day, 'enabled', $event)
              "
            />
            <span class="w-8 shrink-0 text-sm font-medium">
              {{ dayMeta.label }}
            </span>
            <template
              v-if="
                props.form.strategy_config.business_hours.schedule.find(
                  (day) => day.day === dayMeta.day,
                )?.enabled
              "
            >
              <Input
                type="time"
                class="w-32"
                :model-value="
                  props.form.strategy_config.business_hours.schedule.find(
                    (day) => day.day === dayMeta.day,
                  )?.open ?? '09:00'
                "
                @change="
                  updateScheduleDay(
                    dayMeta.day,
                    'open',
                    ($event.target as HTMLInputElement).value,
                  )
                "
              />
              <span class="text-muted-foreground">-</span>
              <Input
                type="time"
                class="w-32"
                :model-value="
                  props.form.strategy_config.business_hours.schedule.find(
                    (day) => day.day === dayMeta.day,
                  )?.close ?? '18:00'
                "
                @change="
                  updateScheduleDay(
                    dayMeta.day,
                    'close',
                    ($event.target as HTMLInputElement).value,
                  )
                "
              />
            </template>
            <span v-else class="text-sm text-muted-foreground">
              {{ t('休息') }}
            </span>
          </div>
        </div>
        <InputError :message="strategyError('business_hours.schedule')" />
      </div>

      <div class="grid gap-2">
        <Label for="plan_business_hours_outside_hours_notice" required>
          {{ t('时段外提示') }}
        </Label>
        <Textarea
          id="plan_business_hours_outside_hours_notice"
          v-model="
            props.form.strategy_config.business_hours.outside_hours_notice
          "
          rows="3"
          required
        />
        <InputError
          :message="strategyError('business_hours.outside_hours_notice')"
        />
      </div>
    </template>
  </div>
</template>
