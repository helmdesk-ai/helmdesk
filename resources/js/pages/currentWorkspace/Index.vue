<!--
  文件说明：当前工作区页面，承接工作区选择、创建和切换流程。
-->
<script setup lang="ts">
import DeleteCurrentWorkspaceAction from '@/actions/App/Actions/Manage/DeleteCurrentWorkspaceAction';
import UpdateWorkspaceAction from '@/actions/App/Actions/Manage/UpdateWorkspaceAction';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import ImageUploadField from '@/components/common/ImageUploadField.vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import WorkspaceSettingsLayout from '@/layouts/WorkspaceSettingsLayout.vue';

import { type AppPageProps } from '@/types';
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';
const { t } = useI18n();
const page = usePage<AppPageProps>();
const generalSettings = computed(() => page.props.generalSettings);
const currentWorkspace = useRequiredWorkspace();
const slugInput = ref<string>(currentWorkspace.value.slug);
const copied = ref(false);
const showDeleteDialog = ref(false);
const deleting = ref(false);

// 计算完整的访问路径
const fullAccessUrl = computed(() => {
  const baseUrl = generalSettings.value.base_url;
  return `${baseUrl}/w/${slugInput.value}`;
});

// 判断是否是默认工作区
const isDefaultWorkspace = computed(() => {
  return currentWorkspace.value.owner_id !== null;
});

const copyToClipboard = async () => {
  try {
    await navigator.clipboard.writeText(fullAccessUrl.value);
    copied.value = true;
    setTimeout(() => {
      copied.value = false;
    }, 2000);
  } catch (err) {
    console.error('Failed to copy:', err);
  }
};

const handleDelete = () => {
  deleting.value = true;
  router.delete(DeleteCurrentWorkspaceAction.url(currentWorkspace.value.slug), {
    preserveState: false,
    preserveScroll: false,
    onSuccess: () => {
      showDeleteDialog.value = false;
    },
    onFinish: () => {
      deleting.value = false;
    },
  });
};
</script>

<template>
  <AppLayout>
    <Head :title="t('常规设置')" />

    <WorkspaceSettingsLayout>
      <div class="space-y-6">
        <HeadingSmall
          :title="t('常规设置')"
          :description="t('配置工作区的基本信息和设置')"
        />

        <Form
          :action="UpdateWorkspaceAction.url(currentWorkspace.slug)"
          method="put"
          class="space-y-6"
          v-slot="{ errors, processing }"
        >
          <div class="grid gap-2">
            <Label for="workspace_id">{{ t('工作区ID') }}</Label>
            <Input
              id="workspace_id"
              name="slug"
              class="mt-1 block w-full bg-gray-50"
              :default-value="currentWorkspace.slug"
              disabled
              readonly
            />
          </div>

          <div class="grid gap-2">
            <Label for="name" required>{{ t('工作区名称') }}</Label>
            <Input
              id="name"
              name="name"
              class="mt-1 block w-full"
              :default-value="currentWorkspace.name"
              required
            />
            <InputError class="mt-2" :message="errors.name" />
          </div>

          <ImageUploadField
            :label="t('工作区Logo')"
            name="logo_id"
            purpose="avatar"
            :upload-context="{ workspace_id: currentWorkspace.id }"
            :initial-preview="currentWorkspace.logo_url || ''"
            :initial-value="currentWorkspace.logo_id || ''"
            variant="logo"
            :error="errors.logo"
            help-text=""
          />

          <div class="grid gap-2">
            <Label for="slug" required>{{ t('访问路径') }}</Label>
            <Input
              id="slug"
              name="slug"
              class="mt-1 block w-full"
              :default-value="currentWorkspace.slug"
              v-model="slugInput"
              required
            />
            <div class="mt-1 flex items-center gap-1.5">
              <p class="text-sm text-muted-foreground">
                {{ fullAccessUrl }}
              </p>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                :aria-label="copied ? t('已复制') : t('复制访问路径')"
                @click="copyToClipboard"
                class="h-6 shrink-0 px-2"
              >
                <Check v-if="copied" class="h-3.5 w-3.5" />
                <Copy v-else class="h-3.5 w-3.5" />
              </Button>
            </div>
            <InputError class="mt-2" :message="errors.slug" />
          </div>

          <FormActions
            :submit-label="t('保存')"
            :processing="processing"
            submit-data-test="update-workspace-button"
          >
            <Button
              v-if="!isDefaultWorkspace"
              type="button"
              variant="destructive"
              @click="showDeleteDialog = true"
            >
              {{ t('删除工作区') }}
            </Button>
          </FormActions>
        </Form>
      </div>
    </WorkspaceSettingsLayout>

    <ConfirmDeleteDialog
      :open="showDeleteDialog"
      :title="t('确认删除工作区')"
      :detail-title="currentWorkspace.name"
      :detail-description="
        t('确定要删除该工作区吗？删除后会进入回收站，需要超级管理员才能恢复。')
      "
      :processing="deleting"
      @update:open="showDeleteDialog = $event"
      @confirm="handleDelete"
    />
  </AppLayout>
</template>
