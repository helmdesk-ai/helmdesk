<!--
  文件说明：认证页面，承接 Fortify 登录、注册、重置密码和邮箱验证流程。
-->
<script setup lang="ts">
import InputError from '@/components/common/InputError.vue';
import TextLink from '@/components/common/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { email } from '@/routes/password';
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
  status?: string;
}>();

const { t } = useI18n();
</script>

<template>
  <AuthLayout
    :title="t('忘记密码')"
    :description="t('输入你的电子邮件以接收密码重置链接')"
  >
    <Head :title="t('忘记密码')" />

    <div
      v-if="status"
      class="mb-4 text-center text-sm font-medium text-muted-foreground"
    >
      {{ status }}
    </div>

    <div class="space-y-6">
      <Form :action="email.url()" method="post" v-slot="{ errors, processing }">
        <div class="grid gap-2">
          <Label for="email">{{ t('电子邮件地址') }}</Label>
          <Input
            id="email"
            type="email"
            name="email"
            autocomplete="off"
            autofocus
          />
          <InputError :message="errors.email" />
        </div>

        <div class="my-6 flex items-center justify-start">
          <Button
            class="w-full"
            :disabled="processing"
            data-test="email-password-reset-link-button"
          >
            <Spinner v-if="processing" />
            {{ t('发送密码重置链接') }}
          </Button>
        </div>
      </Form>

      <div class="space-x-1 text-center text-sm text-muted-foreground">
        <span>{{ t('或者，返回') }}</span>
        <TextLink :href="login()">{{ t('登录') }}</TextLink>
      </div>
    </div>
  </AuthLayout>
</template>
