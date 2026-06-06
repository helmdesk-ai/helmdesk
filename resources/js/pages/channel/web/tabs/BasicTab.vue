<!--
  文件说明：网站渠道基本信息标签页，承接渠道名称、描述与接待方案绑定（渠道自动跟随方案最新版）。
-->
<script setup lang="ts">
import Web from '@/actions/App/Actions/Channel/Web';
import Plan from '@/actions/App/Actions/Reception/Plan';
import FormActions from '@/components/common/FormActions.vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { useChannelPreviewDraft } from '@/composables/useChannelPreviewDraft';
import { useI18n } from '@/composables/useI18n';
import type {
  WebChannelData,
  WebChannelFormOptionsData,
} from '@/types/generated';
import { Form, Link } from '@inertiajs/vue3';
import { AlertCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
  channel: WebChannelData;
  formOptions: WebChannelFormOptionsData;
}>();

const { t } = useI18n();
const draft = useChannelPreviewDraft();

const allOptions = computed(() => props.formOptions.reception_plan_options);
const usableOptions = computed(() =>
  allOptions.value.filter((option) => option.is_usable),
);
const hasUsableOptions = computed(() => usableOptions.value.length > 0);
const hasAnyOptions = computed(() => allOptions.value.length > 0);

const currentBindingExistsInOptions = computed(() =>
  allOptions.value.some(
    (option) => option.id === props.channel.reception_plan_id,
  ),
);

const currentBindingIsUsable = computed(
  () =>
    props.channel.reception_plan_id !== null &&
    Boolean(props.channel.reception_plan_status_detail?.is_valid),
);

const hasStaleBinding = computed(
  () =>
    Boolean(props.channel.reception_plan_id) && !currentBindingIsUsable.value,
);

const selectedPlanId = ref(props.channel.reception_plan_id ?? '');
const defaultVisitorLocale = ref(props.channel.default_visitor_locale);
const submittedPlanId = computed(() => selectedPlanId.value);
const canSubmitForm = computed(
  () =>
    submittedPlanId.value !== '' &&
    (hasUsableOptions.value || hasStaleBinding.value),
);

const staleReasonLabel = computed(
  () =>
    props.channel.reception_plan_status_detail?.reason_label ??
    t('接待方案当前不可用'),
);

const staleBindingLabel = computed(
  () => props.channel.reception_plan_name ?? t('当前绑定的接待方案'),
);

watch(
  () => props.channel,
  (channel) => {
    selectedPlanId.value = channel.reception_plan_id ?? '';
    defaultVisitorLocale.value = channel.default_visitor_locale;
  },
);
</script>

<template>
  <Form
    :action="
      Web.UpdateWebChannelBasicAction.url({
        channel: props.channel.id,
      })
    "
    method="put"
    class="space-y-6"
  >
    <template #default="{ errors, processing }">
      <div class="space-y-5">
        <div class="grid gap-2">
          <Label for="basic_name" required>{{ t('渠道名称') }}</Label>
          <Input
            id="basic_name"
            v-model="draft.channelName"
            name="name"
            required
          />
          <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
          <Label for="basic_description">{{ t('渠道描述') }}</Label>
          <Textarea
            id="basic_description"
            name="description"
            rows="3"
            :default-value="props.channel.description ?? ''"
          />
          <InputError :message="errors.description" />
        </div>

        <div class="grid gap-2">
          <Label for="basic_reception_plan_id" required>
            {{ t('接待方案') }}
          </Label>
          <Select
            v-model="selectedPlanId"
            :disabled="processing || (!hasUsableOptions && !hasStaleBinding)"
          >
            <SelectTrigger id="basic_reception_plan_id" class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-if="
                  hasStaleBinding &&
                  !currentBindingExistsInOptions &&
                  props.channel.reception_plan_id
                "
                :value="props.channel.reception_plan_id"
                disabled
              >
                {{ staleBindingLabel }} · {{ staleReasonLabel }}
              </SelectItem>
              <SelectItem
                v-for="option in allOptions"
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
            :value="submittedPlanId"
          />
          <div
            v-if="hasStaleBinding"
            class="flex flex-wrap items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
          >
            <AlertCircle class="mt-0.5 size-4 shrink-0" />
            <div class="space-y-1">
              <p class="font-medium">
                {{ t('当前绑定的接待方案已失效') }} ·
                {{ staleReasonLabel }}
              </p>
              <p>
                {{
                  t(
                    '不影响保存其他配置，但在切换到可用方案之前，此渠道无法新建 AI 接待会话。',
                  )
                }}
              </p>
            </div>
          </div>
          <div
            v-else-if="!hasAnyOptions"
            class="flex flex-wrap items-center gap-2"
          >
            <Button size="sm" variant="outline" as-child>
              <Link :href="Plan.ShowReceptionPlanIndexPageAction.url()">
                {{ t('去创建接待方案') }}
              </Link>
            </Button>
          </div>
          <InputError :message="errors.reception_plan_id" />
        </div>

        <div class="grid gap-2">
          <Label for="basic_default_visitor_locale" required>
            {{ t('默认接待语言') }}
          </Label>
          <Select v-model="defaultVisitorLocale" :disabled="processing">
            <SelectTrigger id="basic_default_visitor_locale" class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in props.formOptions.reception_language_options"
                :key="option.value"
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

      <FormActions
        :submit-label="t('保存')"
        :processing="processing"
        :submit-disabled="!canSubmitForm"
      />
    </template>
  </Form>
</template>
