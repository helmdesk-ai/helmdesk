<!--
  文件说明：系统工作区管理页面，承接工作区列表、创建、编辑、详情和回收站。
-->
<script setup lang="ts">
import FormActions from '@/components/common/FormActions.vue';
import FormField from '@/components/common/FormField.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import ImageUploadField from '@/components/common/ImageUploadField.vue';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import admin from '@/routes/admin';
import type { ShowCreateWorkspacePagePropsData } from '@/types/generated';
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const { t } = useI18n();
const props = defineProps<ShowCreateWorkspacePagePropsData>();
const page = usePage<any>();
const generalSettings = computed(() => page.props.generalSettings);

const ownerId = ref<string>('');
const slugInput = ref<string>('');

watch(
  () => props.owner_options,
  (opts) => {
    if (!ownerId.value && opts?.length) {
      ownerId.value = String(opts[0].id);
    }
  },
  { immediate: true },
);

const fullAccessUrl = computed(() => {
  const baseUrl = generalSettings.value.base_url;
  return `${baseUrl}/w/${slugInput.value}`;
});
</script>

<template>
  <SystemAppLayout>
    <Head :title="t('创建工作区')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <HeadingSmall
            :title="t('创建工作区')"
            :description="t('创建一个新的工作区并指定所有者')"
          />

          <Form
            :action="admin.workspaces.store.url()"
            method="post"
            class="space-y-6"
            v-slot="{ errors, processing }"
          >
            <FormField
              :label="t('名称')"
              label-for="name"
              :error="errors.name"
              required
            >
              <Input id="name" name="name" class="mt-1 block w-full" required />
            </FormField>

            <ImageUploadField
              :label="t('工作区Logo')"
              name="logo_id"
              purpose="avatar"
              :initial-preview="''"
              :initial-value="''"
              variant="logo"
              :error="errors.logo_id"
            />

            <FormField
              :label="t('访问路径')"
              label-for="slug"
              :help="fullAccessUrl"
              :error="errors.slug"
              required
            >
              <Input
                id="slug"
                name="slug"
                class="mt-1 block w-full"
                v-model="slugInput"
                required
              />
            </FormField>

            <FormField
              :label="t('所有者')"
              label-for="owner_id"
              :error="errors.owner_id"
            >
              <input type="hidden" name="owner_id" :value="ownerId" />
              <Select v-model="ownerId">
                <SelectTrigger id="owner_id" class="mt-1 w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem
                    v-for="u in props.owner_options"
                    :key="String(u.id)"
                    :value="String(u.id)"
                  >
                    {{ u.name }} ({{ u.email }})
                  </SelectItem>
                </SelectContent>
              </Select>
            </FormField>

            <FormActions
              :submit-label="t('创建')"
              :processing="processing"
              :cancel-href="admin.workspaces.index.url()"
            />
          </Form>
        </div>
      </div>
    </div>
  </SystemAppLayout>
</template>
