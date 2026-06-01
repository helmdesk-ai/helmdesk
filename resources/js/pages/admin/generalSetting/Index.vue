<!--
  文件说明：系统通用设置页面，承接站点基础信息配置。
-->
<script setup lang="ts">
import SystemSetting from '@/actions/App/Actions/SystemSetting';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import ImageUploadField from '@/components/common/ImageUploadField.vue';
import InputError from '@/components/common/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useI18n } from '@/composables/useI18n';
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import type { GeneralSettingsData } from '@/types/generated';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const { t } = useI18n();
const props = defineProps<{ generalSettings: GeneralSettingsData }>();
const allowRegistration = ref(props.generalSettings.allow_registration);
</script>

<template>
  <SystemAppLayout>
    <Head :title="t('基础设置')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <HeadingSmall
            :title="t('基础设置')"
            :description="t('配置系统的基本信息')"
          />

          <Form
            :action="SystemSetting.UpdateGeneralSettingAction.url()"
            method="put"
            class="space-y-6"
            v-slot="{ errors, processing }"
          >
            <div class="grid gap-2">
              <Label for="base_url" required>{{ t('主机地址') }}</Label>
              <Input
                id="base_url"
                name="base_url"
                type="url"
                class="mt-1 block w-full"
                :default-value="props.generalSettings.base_url"
                required
              />
              <InputError class="mt-2" :message="errors.base_url" />
            </div>

            <div class="grid gap-2">
              <Label for="name" required>{{ t('系统名称') }}</Label>
              <Input
                id="name"
                name="name"
                class="mt-1 block w-full"
                :default-value="props.generalSettings.name"
                required
              />
              <InputError class="mt-2" :message="errors.name" />
            </div>

            <ImageUploadField
              :label="t('系统Logo')"
              name="logo_id"
              purpose="channel_icon"
              :initial-preview="props.generalSettings.logo_url || ''"
              :initial-value="props.generalSettings.logo_id || ''"
              variant="logo"
              :error="errors.logo_id"
              help-text=""
            />

            <div class="grid gap-2">
              <Label for="copyright">{{ t('版权信息') }}</Label>
              <Input
                id="copyright"
                name="copyright"
                class="mt-1 block w-full"
                :default-value="props.generalSettings.copyright || undefined"
              />
              <InputError class="mt-2" :message="errors.copyright" />
            </div>

            <div class="grid gap-2">
              <Label for="icp_record">{{ t('备案信息') }}</Label>
              <Input
                id="icp_record"
                name="icp_record"
                class="mt-1 block w-full"
                :default-value="props.generalSettings.icp_record || undefined"
              />
              <InputError class="mt-2" :message="errors.icp_record" />
            </div>

            <div class="flex items-center justify-between gap-4">
              <input
                type="hidden"
                name="allow_registration"
                :value="allowRegistration ? '1' : '0'"
              />
              <div class="space-y-1">
                <Label>{{ t('允许用户自主注册') }}</Label>
                <p class="text-sm text-muted-foreground">
                  {{ t('关闭后登录页不再展示注册入口。') }}
                </p>
                <InputError class="mt-2" :message="errors.allow_registration" />
              </div>
              <Switch
                :model-value="allowRegistration"
                :aria-label="t('允许用户自主注册')"
                @update:model-value="
                  (checked) => (allowRegistration = Boolean(checked))
                "
              />
            </div>

            <div class="grid gap-2">
              <Label>{{ t('版本号') }}</Label>
              <div class="py-2 text-sm text-muted-foreground">
                {{ props.generalSettings.version || t('未设置') }}
              </div>
            </div>

            <FormActions
              :submit-label="t('保存')"
              :processing="processing"
              submit-data-test="update-general-settings-button"
            />
          </Form>
        </div>
      </div>
    </div>
  </SystemAppLayout>
</template>
