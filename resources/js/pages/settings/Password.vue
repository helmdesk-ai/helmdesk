<!--
  文件说明：个人设置页面，消费后端设置数据并提交用户偏好表单。
-->
<script setup lang="ts">
import FormActions from '@/components/common/FormActions.vue';
import InputError from '@/components/common/InputError.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import { update } from '@/routes/settings/password';
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCurrentWorkspace } from '@/composables/useWorkspace';
const { t } = useI18n();
const page = usePage();
const currentWorkspace = useCurrentWorkspace();
const RootLayout = computed(() =>
  page.props.auth.user.is_super_admin ? SystemAppLayout : AppLayout,
);
const linkOptions = computed(() => ({
  mergeQuery: {
    from_workspace: currentWorkspace.value?.slug ?? '',
  },
}));
</script>

<template>
  <component :is="RootLayout">
    <Head :title="t('密码设置')" />

    <SettingsLayout>
      <div class="space-y-6">
        <HeadingSmall
          :title="t('修改密码')"
          :description="t('确保你的账户使用长且随机的密码以保证安全')"
        />

        <Form
          :action="update.url(linkOptions)"
          method="put"
          :options="{ preserveScroll: true }"
          reset-on-success
          :reset-on-error="[
            'password',
            'password_confirmation',
            'current_password',
          ]"
          class="space-y-6"
          v-slot="{ errors, processing }"
        >
          <!-- Hidden username field for password managers -->
          <input
            type="text"
            name="username"
            :value="page.props.auth.user.email"
            autocomplete="username"
            style="display: none"
            aria-hidden="true"
            tabindex="-1"
          />

          <div class="grid gap-2">
            <Label for="current_password">{{ t('当前密码') }}</Label>
            <Input
              id="current_password"
              name="current_password"
              type="password"
              class="mt-1 block w-full"
              autocomplete="current-password"
            />
            <InputError :message="errors.current_password" />
          </div>

          <div class="grid gap-2">
            <Label for="password">{{ t('新密码') }}</Label>
            <Input
              id="password"
              name="password"
              type="password"
              class="mt-1 block w-full"
              autocomplete="new-password"
            />
            <InputError :message="errors.password" />
          </div>

          <div class="grid gap-2">
            <Label for="password_confirmation">{{ t('确认密码') }}</Label>
            <Input
              id="password_confirmation"
              name="password_confirmation"
              type="password"
              class="mt-1 block w-full"
              autocomplete="new-password"
            />
            <InputError :message="errors.password_confirmation" />
          </div>

          <FormActions
            :submit-label="t('保存')"
            :processing="processing"
            submit-data-test="update-password-button"
          />
        </Form>
      </div>
    </SettingsLayout>
  </component>
</template>
