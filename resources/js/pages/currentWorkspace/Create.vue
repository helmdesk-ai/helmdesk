<!--
  文件说明：当前工作区页面，承接工作区选择、创建和切换流程。
-->
<script setup lang="ts">
import CreateWorkspaceAction from '@/actions/App/Actions/Manage/CreateWorkspaceAction';
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
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const { t } = useI18n();
const page = usePage<AppPageProps>();
const generalSettings = computed(() => page.props.generalSettings);
const currentWorkspace = useRequiredWorkspace();
const slugInput = ref<string>('');
const copied = ref(false);

// 计算完整的访问路径
const fullAccessUrl = computed(() => {
  const baseUrl = generalSettings.value.base_url;
  return `${baseUrl}/w/${slugInput.value}`;
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
</script>

<template>
  <AppLayout>
    <Head :title="t('创建工作区')" />

    <WorkspaceSettingsLayout>
      <div class="space-y-6">
        <HeadingSmall
          :title="t('创建工作区')"
          :description="t('创建一个新的工作区来组织你的团队和项目')"
        />

        <Form
          :action="CreateWorkspaceAction.url(currentWorkspace.slug)"
          method="post"
          class="space-y-6"
          v-slot="{ errors, processing }"
        >
          <div class="grid gap-2">
            <Label for="name" required>{{ t('工作区名称') }}</Label>
            <Input id="name" name="name" class="mt-1 block w-full" required />
            <InputError class="mt-2" :message="errors.name" />
          </div>

          <ImageUploadField
            :label="t('工作区Logo')"
            name="logo_id"
            purpose="avatar"
            :initial-preview="''"
            :initial-value="''"
            variant="logo"
            :error="errors.logo"
          />

          <div class="grid gap-2">
            <Label for="slug" required>{{ t('访问路径') }}</Label>
            <Input
              id="slug"
              name="slug"
              class="mt-1 block w-full"
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
            :submit-label="t('创建工作区')"
            :processing="processing"
            submit-data-test="create-workspace-button"
          />
        </Form>
      </div>
    </WorkspaceSettingsLayout>
  </AppLayout>
</template>
