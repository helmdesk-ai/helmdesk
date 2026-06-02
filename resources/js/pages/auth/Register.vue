<!--
  文件说明：认证页面，承接系统初始化时的首个超级管理员注册流程。
-->
<script setup lang="ts">
import InputError from '@/components/common/InputError.vue';
import TextLink from '@/components/common/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import { useTimezone } from '@/composables/useTimezone';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store } from '@/routes/register';
import { Form, Head } from '@inertiajs/vue3';

const { locale, t } = useI18n();
const { timezone } = useTimezone();
</script>

<template>
  <AuthBase
    :title="t('创建账户')"
    :description="t('在下方输入你的详细信息以创建账户')"
  >
    <Head :title="t('注册')" />

    <Form
      :action="store.url()"
      method="post"
      :reset-on-success="['password', 'password_confirmation']"
      v-slot="{ errors, processing }"
      class="flex flex-col gap-6"
    >
      <input type="hidden" name="locale" :value="locale" />
      <input type="hidden" name="timezone" :value="timezone" />

      <div class="grid gap-6">
        <div class="grid gap-2">
          <Label for="name" required>{{ t('姓名') }}</Label>
          <Input
            id="name"
            type="text"
            required
            autofocus
            :tabindex="1"
            autocomplete="name"
            name="name"
          />
          <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
          <Label for="email" required>{{ t('电子邮件地址') }}</Label>
          <Input
            id="email"
            type="email"
            required
            :tabindex="2"
            autocomplete="email"
            name="email"
          />
          <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
          <Label for="password" required>{{ t('密码') }}</Label>
          <Input
            id="password"
            type="password"
            required
            :tabindex="3"
            autocomplete="new-password"
            name="password"
          />
          <InputError :message="errors.password" />
        </div>

        <div class="grid gap-2">
          <Label for="password_confirmation" required>{{
            t('确认密码')
          }}</Label>
          <Input
            id="password_confirmation"
            type="password"
            required
            :tabindex="4"
            autocomplete="new-password"
            name="password_confirmation"
          />
          <InputError :message="errors.password_confirmation" />
        </div>

        <Button
          type="submit"
          class="mt-2 w-full"
          tabindex="5"
          :disabled="processing"
          data-test="register-user-button"
        >
          <Spinner v-if="processing" />
          {{ t('创建账户') }}
        </Button>
      </div>

      <div class="text-center text-sm text-muted-foreground">
        {{ t('已有账户？') }}
        <TextLink
          :href="login()"
          class="underline underline-offset-4"
          :tabindex="6"
          >{{ t('登录') }}</TextLink
        >
      </div>
    </Form>
  </AuthBase>
</template>
