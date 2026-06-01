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
import type { ShowEditTeammatePagePropsData } from '@/types/generated';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
const { t } = useI18n();
const props = defineProps<ShowEditTeammatePagePropsData>();
const currentWorkspace = useRequiredWorkspace();

const roleValue = ref<string>(String(props.user_form.role ?? ''));
</script>

<template>
  <AppLayout>
    <Head :title="t('编辑客服')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <HeadingSmall
            :title="t('编辑客服')"
            :description="t('仅支持调整客服身份')"
          />

          <Form
            :action="
              workspaceRoutes.manage.teammates.update.url({
                slug: currentWorkspace.slug,
                id: props.user_form.id,
              })
            "
            method="put"
            class="space-y-6"
            v-slot="{ errors, processing }"
          >
            <div class="grid gap-2">
              <Label for="name">{{ t('客服名称') }}</Label>
              <Input
                id="name"
                class="mt-1 block w-full"
                disabled
                :default-value="props.user_form.name || ''"
              />
            </div>

            <div class="grid gap-2">
              <Label for="email">{{ t('邮箱') }}</Label>
              <Input
                id="email"
                type="email"
                class="mt-1 block w-full"
                disabled
                :default-value="props.user_form.email || ''"
              />
            </div>

            <div class="grid gap-2">
              <Label for="nickname">{{ t('对外昵称') }}</Label>
              <template v-if="props.can_update_nickname">
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
              <Label for="role">{{ t('身份') }}</Label>
              <template v-if="props.can_update_role">
                <input type="hidden" name="role" :value="roleValue" />
                <Select v-model="roleValue">
                  <SelectTrigger id="role" class="mt-1 w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem
                      v-for="opt in props.role_options"
                      :key="String(opt.value)"
                      :value="String(opt.value)"
                    >
                      {{ opt.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </template>
              <template v-else>
                <input
                  type="hidden"
                  name="role"
                  :value="String(props.user_form.role)"
                />
                <Input
                  id="role"
                  class="mt-1 block w-full"
                  :default-value="props.user_form.role_label"
                  disabled
                />
              </template>
              <InputError class="mt-2" :message="errors.role" />
            </div>

            <FormActions
              :submit-label="t('保存')"
              :processing="processing"
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
