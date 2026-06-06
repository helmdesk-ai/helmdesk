<!--
  文件说明：Telegram 渠道基本信息标签页，承接渠道名称、描述、接待方案与默认访客语言；
  消费后端 TelegramChannelData 与 TelegramChannelFormOptionsData。
-->
<script setup lang="ts">
import Telegram from '@/actions/App/Actions/Channel/Telegram';
import FormActions from '@/components/common/FormActions.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type {
  TelegramChannelData,
  TelegramChannelFormOptionsData,
} from '@/types/generated';
import { Form } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps<{
  channel: TelegramChannelData;
  formOptions: TelegramChannelFormOptionsData;
}>();

const { t } = useI18n();

const receptionPlanId = ref(props.channel.reception_plan_id ?? '');
const defaultVisitorLocale = ref<string>(props.channel.default_visitor_locale);

watch(
  () => props.channel,
  (channel) => {
    receptionPlanId.value = channel.reception_plan_id ?? '';
    defaultVisitorLocale.value = channel.default_visitor_locale;
  },
);
</script>

<template>
  <Form
    :action="
      Telegram.UpdateTelegramChannelBasicAction.url({
        channel: props.channel.id,
      })
    "
    method="put"
    class="space-y-6"
  >
    <template #default="{ errors, processing }">
      <div class="space-y-5">
        <div class="grid gap-2">
          <Label for="tg_basic_name" required>{{ t('渠道名称') }}</Label>
          <Input
            id="tg_basic_name"
            name="name"
            :default-value="props.channel.name"
            required
          />
          <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
          <Label for="tg_basic_description">{{ t('渠道描述') }}</Label>
          <Textarea
            id="tg_basic_description"
            name="description"
            rows="3"
            :default-value="props.channel.description ?? ''"
          />
          <InputError :message="errors.description" />
        </div>

        <div class="grid gap-2">
          <Label for="tg_basic_reception_plan_id" required>
            {{ t('接待方案') }}
          </Label>
          <Select v-model="receptionPlanId" :disabled="processing">
            <SelectTrigger id="tg_basic_reception_plan_id" class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in props.formOptions.reception_plan_options"
                :key="option.id"
                :value="option.id"
                :disabled="!option.is_usable"
              >
                <span class="text-sm">
                  {{ option.name }}
                  <span
                    v-if="!option.is_usable && option.unusable_reason_label"
                    class="ml-2 text-xs text-muted-foreground"
                  >
                    ({{ option.unusable_reason_label }})
                  </span>
                </span>
              </SelectItem>
            </SelectContent>
          </Select>
          <input
            type="hidden"
            name="reception_plan_id"
            :value="receptionPlanId"
          />
          <InputError :message="errors.reception_plan_id" />
        </div>

        <div class="grid gap-2">
          <Label for="tg_basic_default_visitor_locale" required>
            {{ t('默认访客语言') }}
          </Label>
          <Select v-model="defaultVisitorLocale" :disabled="processing">
            <SelectTrigger id="tg_basic_default_visitor_locale" class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in props.formOptions.reception_language_options"
                :key="String(option.value)"
                :value="String(option.value)"
              >
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
          <input
            type="hidden"
            name="default_visitor_locale"
            :value="defaultVisitorLocale"
          />
          <InputError :message="errors.default_visitor_locale" />
        </div>
      </div>

      <FormActions :submit-label="t('保存')" :processing="processing" />
    </template>
  </Form>
</template>
