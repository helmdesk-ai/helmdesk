<!--
  文件说明：团队成员页面，承接成员列表、邀请、创建和编辑流程。
-->
<script setup lang="ts">
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import InputError from '@/components/common/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import workspaceRoutes from '@/routes/workspace';
import type {
  ShowCreateTeammatePagePropsData,
  WorkspaceRole,
} from '@/types/generated';
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
const { t } = useI18n();
const props = defineProps<ShowCreateTeammatePagePropsData>();
const currentWorkspace = useRequiredWorkspace();

const availableUsers = computed(() =>
  Array.isArray(props.available_users) ? props.available_users : [],
);

const userId = ref<string>('');
const roleValue = ref<WorkspaceRole>('operator');

const roleOptions = computed<{ value: WorkspaceRole; label: string }[]>(() =>
  props.role_options.map((opt) => ({
    value: opt.value as WorkspaceRole,
    label: opt.label,
  })),
);

watch(
  () => availableUsers.value,
  (opts) => {
    if (!userId.value && opts?.length) {
      userId.value = String(opts[0].id);
    }
  },
  { immediate: true },
);

watch(
  roleOptions,
  (opts) => {
    if (!roleValue.value && opts?.length) {
      roleValue.value = opts[0].value;
    }
  },
  { immediate: true },
);
</script>

<template>
  <AppLayout>
    <Head :title="t('新增客服')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <HeadingSmall
            :title="t('新增客服')"
            :description="t('关联已有用户并分配身份')"
          />

          <Form
            :action="
              workspaceRoutes.manage.teammates.store.url(currentWorkspace.slug)
            "
            method="post"
            class="space-y-6"
            v-slot="{ errors, processing }"
          >
            <div class="grid gap-2">
              <Label for="user_id">{{ t('用户') }}</Label>
              <input type="hidden" name="user_id" :value="userId" />
              <Select v-model="userId">
                <SelectTrigger id="user_id" class="mt-1 w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem
                    v-for="u in availableUsers"
                    :key="String(u.id)"
                    :value="String(u.id)"
                  >
                    {{ u.name }} ({{ u.email }})
                  </SelectItem>
                </SelectContent>
              </Select>
              <p
                v-if="availableUsers.length === 0"
                class="text-sm text-muted-foreground"
              >
                {{ t('暂无可添加的用户') }}
              </p>
              <InputError class="mt-2" :message="errors.user_id" />
            </div>

            <div class="grid gap-2">
              <Label for="nickname">{{ t('对外昵称') }}</Label>
              <Input id="nickname" name="nickname" class="mt-1 block w-full" />
              <InputError class="mt-2" :message="errors.nickname" />
            </div>

            <div class="grid gap-2">
              <Label for="role">{{ t('身份') }}</Label>
              <input type="hidden" name="role" :value="roleValue" />
              <Select v-model="roleValue">
                <SelectTrigger id="role" class="mt-1 w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem
                    v-for="opt in roleOptions"
                    :key="String(opt.value)"
                    :value="String(opt.value)"
                  >
                    {{ opt.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
              <InputError class="mt-2" :message="errors.role" />
            </div>

            <FormActions
              :submit-label="t('创建')"
              :processing="processing"
              :submit-disabled="
                processing ||
                availableUsers.length === 0 ||
                !userId ||
                !roleValue
              "
              :cancel-href="
                workspaceRoutes.manage.teammates.index.url(
                  currentWorkspace.slug,
                )
              "
            />
          </Form>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
