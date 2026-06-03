<!--
  文件说明：客服编辑页面，承接账号资料、密码、头像和权限更新。
  消费后端 ShowEditTeammatePagePropsData。
-->
<script setup lang="ts">
import Teammate from '@/actions/App/Actions/Teammate';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import ImageUploadField from '@/components/common/ImageUploadField.vue';
import InputError from '@/components/common/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ShowEditTeammatePagePropsData } from '@/types/generated';
import { Form, Head } from '@inertiajs/vue3';
import { Eye, EyeOff } from '@lucide/vue';
import { ref } from 'vue';

const props = defineProps<ShowEditTeammatePagePropsData>();

const { t } = useI18n();

const passwordVisible = ref(false);
const passwordConfirmationVisible = ref(false);
const selectedPermissions = ref<string[]>(
  props.user_form.permissions.map((permission) => String(permission)),
);

function permissionValue(value: string | number): string {
  return String(value);
}

function togglePermission(value: string | number, checked: boolean): void {
  const normalizedValue = permissionValue(value);
  if (checked && !selectedPermissions.value.includes(normalizedValue)) {
    selectedPermissions.value = [...selectedPermissions.value, normalizedValue];
    return;
  }

  if (!checked) {
    selectedPermissions.value = selectedPermissions.value.filter(
      (permission) => permission !== normalizedValue,
    );
  }
}
</script>

<template>
  <AppLayout>
    <Head :title="t('编辑客服')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="space-y-6">
        <HeadingSmall
          :title="t('编辑客服')"
          :description="t('更新客服资料，密码可选不填表示不修改')"
        />

        <Form
          :action="
            Teammate.UpdateTeammateAction.url({
              teammate: props.user_form.id,
            })
          "
          method="put"
          class="space-y-6"
          v-slot="{ errors, processing }"
        >
          <div class="grid gap-2">
            <Label for="name" required>{{ t('名称') }}</Label>
            <template v-if="props.can_update_profile">
              <Input
                id="name"
                name="name"
                class="mt-1 block w-full"
                required
                :default-value="props.user_form.name"
              />
            </template>
            <template v-else>
              <input type="hidden" name="name" :value="props.user_form.name" />
              <Input
                id="name"
                class="mt-1 block w-full"
                disabled
                :default-value="props.user_form.name"
              />
            </template>
            <InputError class="mt-2" :message="errors.name" />
          </div>

          <div class="grid gap-2">
            <Label for="email" required>{{ t('邮箱') }}</Label>
            <template v-if="props.can_update_profile">
              <Input
                id="email"
                name="email"
                type="email"
                class="mt-1 block w-full"
                required
                :default-value="props.user_form.email"
              />
            </template>
            <template v-else>
              <input
                type="hidden"
                name="email"
                :value="props.user_form.email"
              />
              <Input
                id="email"
                type="email"
                class="mt-1 block w-full"
                disabled
                :default-value="props.user_form.email"
              />
            </template>
            <InputError class="mt-2" :message="errors.email" />
          </div>

          <div class="grid gap-2">
            <Label for="nickname">{{ t('对外昵称') }}</Label>
            <template v-if="props.can_update_profile">
              <Input
                id="nickname"
                name="nickname"
                class="mt-1 block w-full"
                :default-value="props.user_form.nickname || ''"
              />
            </template>
            <template v-else>
              <input
                type="hidden"
                name="nickname"
                :value="props.user_form.nickname || ''"
              />
              <Input
                id="nickname"
                class="mt-1 block w-full"
                disabled
                :default-value="props.user_form.nickname || ''"
              />
            </template>
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
            <div class="space-y-5">
              <div
                v-for="group in props.permission_groups"
                :key="group.key"
                class="space-y-3"
              >
                <div class="text-sm font-medium">{{ group.label }}</div>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                  <label
                    v-for="permission in group.permissions"
                    :key="String(permission.value)"
                    class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm"
                  >
                    <Checkbox
                      :model-value="
                        selectedPermissions.includes(
                          permissionValue(permission.value),
                        )
                      "
                      :disabled="!props.can_update_profile"
                      @update:model-value="
                        (checked) =>
                          togglePermission(
                            permission.value,
                            checked === true,
                          )
                      "
                    />
                    <span>{{ permission.label }}</span>
                  </label>
                </div>
              </div>
            </div>
            <InputError class="mt-2" :message="errors.permissions" />
          </div>

          <ImageUploadField
            v-if="props.can_update_profile"
            :label="t('头像')"
            name="avatar_id"
            purpose="avatar"
            :initial-preview="props.user_form.avatar || ''"
            :initial-value="''"
            variant="avatar"
            :error="errors.avatar_id"
          />
          <input v-else type="hidden" name="avatar_id" value="" />

          <template v-if="props.can_update_profile">
            <div class="grid gap-2">
              <Label for="password">{{ t('登录密码') }}</Label>
              <div class="relative mt-1">
                <Input
                  id="password"
                  name="password"
                  :type="passwordVisible ? 'text' : 'password'"
                  class="block w-full pr-10"
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
              <Label for="password_confirmation">{{ t('确认密码') }}</Label>
              <div class="relative mt-1">
                <Input
                  id="password_confirmation"
                  name="password_confirmation"
                  :type="passwordConfirmationVisible ? 'text' : 'password'"
                  class="block w-full pr-10"
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
              <InputError
                class="mt-2"
                :message="errors.password_confirmation"
              />
            </div>
          </template>
          <template v-else>
            <input type="hidden" name="password" value="" />
            <input type="hidden" name="password_confirmation" value="" />
          </template>

          <FormActions
            :submit-label="t('保存')"
            :processing="processing"
            :submit-disabled="processing || !props.can_update_profile"
            :cancel-href="Teammate.ShowTeammateListAction.url()"
          />
        </Form>
      </div>
    </div>
  </AppLayout>
</template>
