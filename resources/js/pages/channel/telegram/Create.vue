<!--
  文件说明：Telegram 渠道创建页面，承接渠道名称、Bot Token、描述与接待方案绑定；
  消费后端 ShowCreateTelegramChannelPagePropsData，提交至 CreateTelegramChannelAction。
-->
<script setup lang="ts">
import Telegram from '@/actions/App/Actions/Channel/Telegram';
import Plan from '@/actions/App/Actions/Reception/Plan';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
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
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import ChannelsLayout from '@/layouts/ChannelsLayout.vue';
import type { ShowCreateTelegramChannelPagePropsData } from '@/types/generated';
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<ShowCreateTelegramChannelPagePropsData>();
const { t } = useI18n();

const usableOptions = computed(() =>
  props.reception_plan_options.filter((option) => option.is_usable),
);
const hasUsableOptions = computed(() => usableOptions.value.length > 0);
const hasAnyOptions = computed(() => props.reception_plan_options.length > 0);

const selectedPlanId = ref(
  usableOptions.value.length === 1 ? usableOptions.value[0].id : '',
);
const canSubmit = computed(
  () => hasUsableOptions.value && selectedPlanId.value !== '',
);
</script>

<template>
  <AppLayout>
    <Head :title="t('创建 Telegram 渠道')" />

    <ChannelsLayout>
      <div class="space-y-6">
        <HeadingSmall
          :title="t('创建 Telegram 渠道')"
          :description="
            t('接入一个 Telegram Bot，访客在 Telegram 上即可与客服对话。')
          "
        />

        <Form
          :action="Telegram.CreateTelegramChannelAction.url()"
          method="post"
          class="space-y-6"
          v-slot="{ errors, processing }"
        >
          <div class="grid gap-2">
            <Label for="channel_name" required>{{ t('渠道名称') }}</Label>
            <Input
              id="channel_name"
              name="name"
              class="mt-1 block w-full"
              required
              autofocus
              autocomplete="off"
            />
            <InputError class="mt-2" :message="errors.name" />
          </div>

          <div class="grid gap-2">
            <Label for="channel_bot_token" required>{{ t('Bot Token') }}</Label>
            <Input
              id="channel_bot_token"
              name="bot_token"
              class="mt-1 block w-full font-mono"
              required
              autocomplete="off"
            />
            <InputError class="mt-2" :message="errors.bot_token" />
          </div>

          <div class="grid gap-2">
            <Label for="channel_description">{{ t('渠道描述') }}</Label>
            <Textarea id="channel_description" name="description" rows="3" />
            <InputError class="mt-2" :message="errors.description" />
          </div>

          <div class="grid gap-2">
            <Label for="channel_reception_plan_id" required>
              {{ t('接待方案') }}
            </Label>
            <Select
              v-model="selectedPlanId"
              :disabled="processing || !hasUsableOptions"
            >
              <SelectTrigger id="channel_reception_plan_id" class="mt-1 w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in props.reception_plan_options"
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
              :value="selectedPlanId"
            />
            <InputError class="mt-2" :message="errors.reception_plan_id" />
          </div>

          <div
            v-if="hasAnyOptions && !hasUsableOptions"
            class="flex items-center gap-3"
          >
            <p class="text-sm text-muted-foreground">
              {{
                t(
                  '当前接待方案均不可用（已归档或默认接待模型失效），请先调整。',
                )
              }}
            </p>
            <Button variant="outline" size="sm" as-child>
              <Link :href="Plan.ShowReceptionPlanIndexPageAction.url()">
                {{ t('管理接待方案') }}
              </Link>
            </Button>
          </div>

          <FormActions
            :submit-label="t('创建')"
            :processing="processing"
            :submit-disabled="!canSubmit"
            :cancel-href="Telegram.ListTelegramChannelsAction.url()"
            :cancel-label="t('取消')"
          >
            <template #submit>
              {{ processing ? t('创建中...') : t('创建') }}
            </template>
          </FormActions>
        </Form>
      </div>
    </ChannelsLayout>
  </AppLayout>
</template>
