<!--
  文件说明：接待方案流程策略表单字段。
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
import type { InertiaForm } from '@inertiajs/vue3';

export type ReceptionRoutingModeDraft = 'ai_first' | 'teammate_first';

export type ReceptionBusinessHoursDayDraft = {
  day: number;
  enabled: boolean;
  open: string;
  close: string;
};

export type ReceptionBusinessHoursDraft = {
  timezone: string;
  outside_hours_notice: string;
  schedule: ReceptionBusinessHoursDayDraft[];
};

export type ReceptionStrategyConfigDraft = {
  reception_mode: ReceptionRoutingModeDraft;
  unassigned_ai_takeover_enabled: boolean;
  unassigned_ai_takeover_timeout_seconds: number;
  teammate_no_response_ai_takeover_enabled: boolean;
  teammate_no_response_ai_takeover_timeout_seconds: number;
  important_contact_ai_careful_reply_enabled: boolean;
  important_contact_ai_handoff_hint_enabled: boolean;
  important_contact_human_first_when_online_enabled: boolean;
  quote_visitor_message_enabled: boolean;
  handoff_available_notice: string;
  handoff_no_teammate_notice: string;
  ai_unavailable_notice: string;
  business_hours: ReceptionBusinessHoursDraft | null;
};

export type PlanStrategyFormShape = {
  strategy_config: ReceptionStrategyConfigDraft;
};

const props = defineProps<{
  form: InertiaForm<PlanStrategyFormShape>;
}>();

const { t } = useI18n();

const routingModeOptions: Array<{
  value: ReceptionRoutingModeDraft;
  label: string;
}> = [
  { value: 'ai_first', label: t('AI 优先') },
  { value: 'teammate_first', label: t('人工优先') },
];

function strategyError(field: string): string | undefined {
  return (props.form.errors as Record<string, string | undefined>)[
    `strategy_config.${field}`
  ];
}
</script>

<template>
  <div class="space-y-6">
    <div class="grid gap-2">
      <Label for="plan_strategy_reception_mode" required>
        {{ t('接待方式') }}
      </Label>
      <Select v-model="props.form.strategy_config.reception_mode">
        <SelectTrigger id="plan_strategy_reception_mode" class="w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem
            v-for="option in routingModeOptions"
            :key="option.value"
            :value="option.value"
          >
            {{ option.label }}
          </SelectItem>
        </SelectContent>
      </Select>
      <InputError :message="strategyError('reception_mode')" />
    </div>

    <div
      v-if="props.form.strategy_config.reception_mode === 'teammate_first'"
      class="space-y-5"
    >
      <div class="flex items-center justify-between gap-4">
        <div class="space-y-1">
          <Label>{{ t('无人接待超时后 AI 接待') }}</Label>
          <InputError
            :message="strategyError('unassigned_ai_takeover_enabled')"
          />
        </div>
        <Switch
          v-model="props.form.strategy_config.unassigned_ai_takeover_enabled"
        />
      </div>
      <div
        v-if="props.form.strategy_config.unassigned_ai_takeover_enabled"
        class="grid gap-2"
      >
        <Label
          for="plan_strategy_unassigned_ai_takeover_timeout_seconds"
          required
        >
          {{ t('无人接待超时时间（秒）') }}
        </Label>
        <Input
          id="plan_strategy_unassigned_ai_takeover_timeout_seconds"
          v-model.number="
            props.form.strategy_config.unassigned_ai_takeover_timeout_seconds
          "
          type="number"
          min="0"
          max="86400"
        />
        <InputError
          :message="strategyError('unassigned_ai_takeover_timeout_seconds')"
        />
      </div>
    </div>

    <div class="space-y-5">
      <div class="flex items-center justify-between gap-4">
        <div class="space-y-1">
          <Label>{{ t('客服无响应后 AI 接管') }}</Label>
          <InputError
            :message="strategyError('teammate_no_response_ai_takeover_enabled')"
          />
        </div>
        <Switch
          v-model="
            props.form.strategy_config.teammate_no_response_ai_takeover_enabled
          "
        />
      </div>
      <div
        v-if="
          props.form.strategy_config.teammate_no_response_ai_takeover_enabled
        "
        class="grid gap-2"
      >
        <Label
          for="plan_strategy_teammate_no_response_ai_takeover_timeout_seconds"
          required
        >
          {{ t('客服无响应时间（秒）') }}
        </Label>
        <Input
          id="plan_strategy_teammate_no_response_ai_takeover_timeout_seconds"
          v-model.number="
            props.form.strategy_config
              .teammate_no_response_ai_takeover_timeout_seconds
          "
          type="number"
          min="0"
          max="86400"
        />
        <InputError
          :message="
            strategyError('teammate_no_response_ai_takeover_timeout_seconds')
          "
        />
      </div>
    </div>

    <div class="space-y-5">
      <div class="flex items-center justify-between gap-4">
        <div class="space-y-1">
          <Label>{{ t('重点客户 AI 谨慎回复') }}</Label>
          <InputError
            :message="
              strategyError('important_contact_ai_careful_reply_enabled')
            "
          />
        </div>
        <Switch
          v-model="
            props.form.strategy_config
              .important_contact_ai_careful_reply_enabled
          "
        />
      </div>

      <div class="flex items-center justify-between gap-4">
        <div class="space-y-1">
          <Label>{{ t('重点客户 AI 风险转人工提示') }}</Label>
          <InputError
            :message="
              strategyError('important_contact_ai_handoff_hint_enabled')
            "
          />
        </div>
        <Switch
          v-model="
            props.form.strategy_config.important_contact_ai_handoff_hint_enabled
          "
        />
      </div>

      <div class="flex items-center justify-between gap-4">
        <div class="space-y-1">
          <Label>{{ t('重点客户人工在线优先接待') }}</Label>
          <InputError
            :message="
              strategyError('important_contact_human_first_when_online_enabled')
            "
          />
        </div>
        <Switch
          v-model="
            props.form.strategy_config
              .important_contact_human_first_when_online_enabled
          "
        />
      </div>
    </div>

    <div class="flex items-center justify-between gap-4">
      <div class="space-y-1">
        <Label>{{ t('AI 回复引用访客消息') }}</Label>
        <InputError :message="strategyError('quote_visitor_message_enabled')" />
      </div>
      <Switch
        v-model="props.form.strategy_config.quote_visitor_message_enabled"
      />
    </div>

    <div class="grid gap-2">
      <Label for="plan_strategy_handoff_available_notice" required>
        {{ t('转人工成功提示语') }}
      </Label>
      <Textarea
        id="plan_strategy_handoff_available_notice"
        v-model="props.form.strategy_config.handoff_available_notice"
        rows="2"
        required
      />
      <InputError :message="strategyError('handoff_available_notice')" />
    </div>

    <div class="grid gap-2">
      <Label for="plan_strategy_handoff_no_teammate_notice" required>
        {{ t('无法转人工提示语') }}
      </Label>
      <Textarea
        id="plan_strategy_handoff_no_teammate_notice"
        v-model="props.form.strategy_config.handoff_no_teammate_notice"
        rows="2"
        required
      />
      <InputError :message="strategyError('handoff_no_teammate_notice')" />
    </div>

    <div class="grid gap-2">
      <Label for="plan_strategy_ai_unavailable_notice" required>
        {{ t('AI 不可用兜底提示语') }}
      </Label>
      <Textarea
        id="plan_strategy_ai_unavailable_notice"
        v-model="props.form.strategy_config.ai_unavailable_notice"
        rows="2"
        required
      />
      <InputError :message="strategyError('ai_unavailable_notice')" />
    </div>
  </div>
</template>
