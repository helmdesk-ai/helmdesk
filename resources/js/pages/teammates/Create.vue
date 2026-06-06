<!--
  文件说明：客服创建页面，承接账号、登录密码、头像和权限配置表单。
  消费后端 ShowCreateTeammatePagePropsData。
-->
<script setup lang="ts">
import Teammate from '@/actions/App/Actions/Teammate';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import ImageUploadField from '@/components/common/ImageUploadField.vue';
import InputError from '@/components/common/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ShowCreateTeammatePagePropsData } from '@/types/generated';
import { Form, Head } from '@inertiajs/vue3';
import { Eye, EyeOff } from '@lucide/vue';
import { ref } from 'vue';
import PermissionSelector from './PermissionSelector.vue';

const props = defineProps<ShowCreateTeammatePagePropsData>();

const { t } = useI18n();

const passwordVisible = ref(false);
const passwordConfirmationVisible = ref(false);
const selectedPermissions = ref<string[]>([]);
</script>

<template>
  <AppLayout>
    <Head :title="t('新增客服')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="space-y-6">
        <HeadingSmall
          :title="t('新增客服')"
          :description="t('创建一个新的客服账号并分配权限')"
        />

        <Form
          :action="Teammate.CreateTeammateAction.url()"
          method="post"
          class="space-y-6"
          v-slot="{ errors, processing }"
        >
          <div class="grid gap-2">
            <Label for="name" required>{{ t('名称') }}</Label>
            <Input id="name" name="name" class="mt-1 block w-full" required />
            <InputError class="mt-2" :message="errors.name" />
          </div>

          <div class="grid gap-2">
            <Label for="email" required>{{ t('邮箱') }}</Label>
            <Input
              id="email"
              name="email"
              type="email"
              class="mt-1 block w-full"
              required
            />
            <InputError class="mt-2" :message="errors.email" />
          </div>

          <div class="grid gap-2">
            <Label for="nickname">{{ t('对外昵称') }}</Label>
            <Input id="nickname" name="nickname" class="mt-1 block w-full" />
            <InputError class="mt-2" :message="errors.nickname" />
          </div>

          <div class="grid gap-2">
            <Label>{{ t('权限') }}</Label>
            <input
              v-for="permission in selectedPermissions"
              :key="permission"
              type="hidden"
              name="permissions[]"
              :value="permission"
            />
            <PermissionSelector
              v-model="selectedPermissions"
              :groups="props.permission_groups"
            />
            <InputError class="mt-2" :message="errors.permissions" />
          </div>

          <ImageUploadField
            :label="t('头像')"
            name="avatar_id"
            purpose="avatar"
            :initial-preview="''"
            :initial-value="''"
            variant="avatar"
            :error="errors.avatar_id"
          />

          <div class="grid gap-2">
            <Label for="password" required>{{ t('登录密码') }}</Label>
            <div class="relative mt-1">
              <Input
                id="password"
                name="password"
                :type="passwordVisible ? 'text' : 'password'"
                class="block w-full pr-10"
                required
              />
              <button
                type="button"
                :aria-label="passwordVisible ? t('隐藏密码') : t('显示密码')"
                class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                @click="passwordVisible = !passwordVisible"
              >
                <EyeOff v-if="passwordVisible" class="h-4 w-4" />
                <Eye v-else class="h-4 w-4" />
              </button>
            </div>
            <InputError class="mt-2" :message="errors.password" />
          </div>

          <div class="grid gap-2">
            <Label for="password_confirmation" required>
              {{ t('确认密码') }}
            </Label>
            <div class="relative mt-1">
              <Input
                id="password_confirmation"
                name="password_confirmation"
                :type="passwordConfirmationVisible ? 'text' : 'password'"
                class="block w-full pr-10"
                required
              />
              <button
                type="button"
                :aria-label="
                  passwordConfirmationVisible ? t('隐藏密码') : t('显示密码')
                "
                class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                @click="
                  passwordConfirmationVisible = !passwordConfirmationVisible
                "
              >
                <EyeOff v-if="passwordConfirmationVisible" class="h-4 w-4" />
                <Eye v-else class="h-4 w-4" />
              </button>
            </div>
            <InputError class="mt-2" :message="errors.password_confirmation" />
          </div>

          <FormActions
            :submit-label="t('创建')"
            :processing="processing"
            :cancel-href="Teammate.ShowTeammateListAction.url()"
          />
        </Form>
      </div>
    </div>
  </AppLayout>
</template>
