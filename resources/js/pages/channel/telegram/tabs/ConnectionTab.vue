<!--
  文件说明：Telegram 渠道接入设置标签页，承接 Webhook 状态与重新注册、Bot Token 轮换；
  消费后端 TelegramChannelData。
-->
<script setup lang="ts">
import Telegram from '@/actions/App/Actions/Channel/Telegram';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import InputError from '@/components/common/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useI18n } from '@/composables/useI18n';
import type { TelegramChannelData } from '@/types/generated';
import { Form } from '@inertiajs/vue3';
import { Bot } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
  channel: TelegramChannelData;
}>();

const { t } = useI18n();

const botToken = ref('');
</script>

<template>
  <div class="space-y-8">
    <!-- 当前绑定的 Bot 身份 -->
    <div
      class="flex items-center gap-2 rounded-md border bg-muted/30 px-3 py-2.5 text-sm"
    >
      <Bot class="h-4 w-4 shrink-0 text-muted-foreground" />
      <span class="text-muted-foreground">{{ t('当前 Bot') }}</span>
      <span v-if="props.channel.bot_username" class="font-medium">
        @{{ props.channel.bot_username }}
      </span>
      <span v-else class="text-muted-foreground">{{ t('未获取到名称') }}</span>
    </div>

    <!-- Webhook 状态：只读地址 + 重新注册 -->
    <section class="space-y-4">
      <div class="flex items-start justify-between gap-4">
        <HeadingSmall
          :title="t('Webhook')"
          :description="t('Telegram 通过该地址把访客消息推送到本系统。')"
        />
        <Badge
          :variant="props.channel.webhook_active ? 'default' : 'secondary'"
        >
          {{ props.channel.webhook_active ? t('正在接收消息') : t('未注册') }}
        </Badge>
      </div>

      <div class="grid gap-2">
        <Label for="tg_webhook_url">{{ t('Webhook 地址') }}</Label>
        <Input
          id="tg_webhook_url"
          :model-value="props.channel.webhook_url"
          readonly
          class="w-full font-mono text-xs"
        />
      </div>

      <Form
        :action="
          Telegram.RegisterTelegramWebhookAction.url({
            channel: props.channel.id,
          })
        "
        method="post"
      >
        <template #default="{ processing }">
          <Button
            type="submit"
            variant="outline"
            size="sm"
            :disabled="processing"
          >
            {{ processing ? t('注册中...') : t('重新注册 Webhook') }}
          </Button>
        </template>
      </Form>
    </section>

    <Separator />

    <!-- Bot Token 轮换 -->
    <Form
      :action="
        Telegram.UpdateTelegramChannelTokenAction.url({
          channel: props.channel.id,
        })
      "
      method="put"
      class="space-y-6"
      @success="botToken = ''"
    >
      <template #default="{ errors, processing }">
        <HeadingSmall
          :title="t('Bot Token')"
          :description="
            t('Token 已加密保存，不会回显。需要更换时填写新 Token 重新绑定。')
          "
        />

        <div class="grid gap-2">
          <Label for="tg_bot_token" required>{{ t('新的 Bot Token') }}</Label>
          <Input
            id="tg_bot_token"
            v-model="botToken"
            name="bot_token"
            class="w-full font-mono"
            autocomplete="off"
            placeholder="123456789:AA..."
          />
          <InputError :message="errors.bot_token" />
        </div>

        <FormActions
          :submit-label="t('更新 Token')"
          :processing="processing"
          :submit-disabled="botToken.trim() === ''"
        />
      </template>
    </Form>
  </div>
</template>
