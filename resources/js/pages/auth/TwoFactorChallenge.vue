<!--
  文件说明：认证页面，承接 Fortify 登录、注册、重置密码和邮箱验证流程。
-->
<script setup lang="ts">
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSlot,
} from '@/components/ui/input-otp';
import { useI18n } from '@/composables/useI18n';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/two-factor/login';
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const { t } = useI18n();

interface AuthConfigContent {
  title: string;
  description: string;
  toggleText: string;
}

const authConfigContent = computed<AuthConfigContent>(() => {
  if (showRecoveryInput.value) {
    return {
      title: t('恢复码'),
      description: t('请输入你的紧急恢复码之一来确认访问你的账户。'),
      toggleText: t('使用身份验证码登录'),
    };
  }

  return {
    title: t('身份验证码'),
    description: t('输入你的身份验证器应用程序提供的验证码。'),
    toggleText: t('使用恢复码登录'),
  };
});

const showRecoveryInput = ref<boolean>(false);

const toggleRecoveryMode = (clearErrors: () => void): void => {
  showRecoveryInput.value = !showRecoveryInput.value;
  clearErrors();
  code.value = '';
};

const code = ref<string>('');
</script>

<template>
  <AuthLayout
    :title="authConfigContent.title"
    :description="authConfigContent.description"
  >
    <Head :title="t('两步验证')" />

    <div class="space-y-6">
      <template v-if="!showRecoveryInput">
        <Form
          :action="store.url()"
          method="post"
          class="space-y-4"
          reset-on-error
          @error="code = ''"
          #default="{ errors, processing, clearErrors }"
        >
          <input type="hidden" name="code" :value="code" />
          <div
            class="flex flex-col items-center justify-center space-y-3 text-center"
          >
            <div class="flex w-full items-center justify-center">
              <InputOTP
                id="otp"
                v-model="code"
                :maxlength="6"
                :disabled="processing"
                autofocus
              >
                <InputOTPGroup>
                  <InputOTPSlot
                    v-for="index in 6"
                    :key="index"
                    :index="index - 1"
                  />
                </InputOTPGroup>
              </InputOTP>
            </div>
            <InputError :message="errors.code" />
          </div>
          <Button type="submit" class="w-full" :disabled="processing">{{
            t('继续')
          }}</Button>
          <div class="text-center text-sm text-muted-foreground">
            <span>{{ t('或者你可以') }} </span>
            <button
              type="button"
              class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
              @click="() => toggleRecoveryMode(clearErrors)"
            >
              {{ authConfigContent.toggleText }}
            </button>
          </div>
        </Form>
      </template>

      <template v-else>
        <Form
          :action="store.url()"
          method="post"
          class="space-y-4"
          reset-on-error
          #default="{ errors, processing, clearErrors }"
        >
          <Input
            name="recovery_code"
            type="text"
            :autofocus="showRecoveryInput"
            required
          />
          <InputError :message="errors.recovery_code" />
          <Button type="submit" class="w-full" :disabled="processing">{{
            t('继续')
          }}</Button>

          <div class="text-center text-sm text-muted-foreground">
            <span>{{ t('或者你可以') }} </span>
            <button
              type="button"
              class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
              @click="() => toggleRecoveryMode(clearErrors)"
            >
              {{ authConfigContent.toggleText }}
            </button>
          </div>
        </Form>
      </template>
    </div>
  </AuthLayout>
</template>
