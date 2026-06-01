<!--
  文件说明：认证页面，承接 Fortify 登录、注册、重置密码和邮箱验证流程。
-->
<script setup lang="ts">
import InputError from '@/components/common/InputError.vue';
import TextLink from '@/components/common/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import AuthBase from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
  status?: string;
  canResetPassword: boolean;
  canRegister: boolean;
}>();

const { t } = useI18n();
</script>

<template>
  <AuthBase
    :title="t('登录你的账户')"
    :description="t('在下方输入你的邮箱和密码以登录')"
  >
    <Head :title="t('登录')" />

    <div
      v-if="status"
      class="mb-4 text-center text-sm font-medium text-muted-foreground"
    >
      {{ status }}
    </div>

    <Form
      :action="store.url()"
      method="post"
      :reset-on-success="['password']"
      v-slot="{ errors, processing }"
      class="flex flex-col gap-6"
    >
      <div class="grid gap-6">
        <div class="grid gap-2">
          <Label for="email" required>{{ t('电子邮件地址') }}</Label>
          <Input
            id="email"
            type="email"
            name="email"
            required
            autofocus
            :tabindex="1"
            autocomplete="email"
          />
          <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
          <div class="flex items-center justify-between">
            <Label for="password" required>{{ t('密码') }}</Label>
            <TextLink
              v-if="canResetPassword"
              :href="request()"
              class="text-sm"
              :tabindex="5"
            >
              {{ t('忘记密码？') }}
            </TextLink>
          </div>
          <Input
            id="password"
            type="password"
            name="password"
            required
            :tabindex="2"
            autocomplete="current-password"
          />
          <InputError :message="errors.password" />
        </div>

        <div class="flex items-center justify-between">
          <Label for="remember" class="flex items-center space-x-3">
            <Checkbox id="remember" name="remember" :tabindex="3" />
            <span>{{ t('记住我') }}</span>
          </Label>
        </div>

        <Button
          type="submit"
          class="mt-4 w-full"
          :tabindex="4"
          :disabled="processing"
          data-test="login-button"
        >
          <Spinner v-if="processing" />
          {{ t('登录') }}
        </Button>
      </div>

      <div class="text-center text-sm text-muted-foreground" v-if="canRegister">
        {{ t('没有账户？') }}
        <TextLink :href="register()" :tabindex="5">{{ t('注册') }}</TextLink>
      </div>
    </Form>
  </AuthBase>
</template>
