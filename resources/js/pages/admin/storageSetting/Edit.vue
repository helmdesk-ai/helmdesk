<!--
  文件说明：系统存储配置编辑页面，使用普通平铺表单布局；
  展示完整连接参数，仅开放配置名称、访问凭据和自定义域名编辑。
-->
<script setup lang="ts">
import CheckStorageSettingAction from '@/actions/App/Actions/StorageSetting/CheckStorageSettingAction';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import admin from '@/routes/admin';
import storageProfile from '@/routes/admin/storage/profiles';
import type {
  FormCheckStorageSettingData,
  FormUpdateStorageProfileData,
  ShowEditStorageProfilePagePropsData,
} from '@/types/generated';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<ShowEditStorageProfilePagePropsData>();
const { t } = useI18n();

const nullToEmpty = (value: string | null | undefined): string => value ?? '';
const emptyToNull = (value: string): string | null =>
  value === '' ? null : value;

const form = useForm<FormUpdateStorageProfileData>({
  name: props.profile.name,
  url: props.profile.url ?? null,
  key: null,
  secret: null,
});

const checkForm = useForm<FormCheckStorageSettingData>({
  provider: '',
  region: '',
  endpoint: '',
  key: '',
  secret: null,
  bucket: '',
  url: null,
});

const currentProvider = computed(() => {
  if (!props.profile.provider) {
    return null;
  }

  return props.providers.find(
    (p) => p.provider.value === props.profile.provider?.value,
  );
});

const currentRegionData = computed(() =>
  currentProvider.value?.regions.find((r) => r.id === props.profile.region),
);

const profileProviderLabel = computed(
  () =>
    currentProvider.value?.provider.label ??
    props.profile.provider?.label ??
    t('本地存储'),
);

const profileRegionLabel = computed(() => {
  if (currentRegionData.value) {
    return `${currentRegionData.value.name} (${currentRegionData.value.id})`;
  }

  return nullToEmpty(props.profile.region);
});

const editUrl = computed<string>({
  get: () => nullToEmpty(form.url),
  set: (value) => {
    form.url = emptyToNull(value);
  },
});

const editKey = computed<string>({
  get: () => nullToEmpty(form.key),
  set: (value) => {
    form.key = emptyToNull(value);
  },
});

const editSecret = computed<string>({
  get: () => nullToEmpty(form.secret),
  set: (value) => {
    form.secret = emptyToNull(value);
  },
});

const errorFor = (field: string): string | undefined =>
  (form.errors as Record<string, string | undefined>)[field] ??
  (checkForm.errors as Record<string, string | undefined>)[field];

const submitting = computed(() => form.processing || checkForm.processing);

const checkConnection = () => {
  checkForm.clearErrors();

  if (!props.profile.provider || (!form.key && !form.secret)) {
    checkForm.put(storageProfile.check.url(props.profile.id), {
      preserveScroll: true,
    });

    return;
  }

  Object.assign(checkForm, {
    provider: props.profile.provider.value,
    region: props.profile.region ?? '',
    endpoint: props.profile.endpoint ?? '',
    key: form.key ?? '',
    secret: form.secret,
    bucket: props.profile.bucket ?? '',
    url: form.url,
  });

  checkForm.put(CheckStorageSettingAction.url(), {
    preserveScroll: true,
  });
};

const submit = () => {
  form.put(storageProfile.update.url(props.profile.id), {
    preserveScroll: true,
  });
};
</script>

<template>
  <SystemAppLayout>
    <Head :title="t('编辑存储配置')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <HeadingSmall
            :title="t('编辑：{name}', { name: profile.name })"
            :description="t('可更新配置名称、访问凭据和自定义域名。')"
          />

          <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
              <Label for="name">{{ t('配置名称') }}</Label>
              <Input id="name" v-model="form.name" autocomplete="off" />
              <InputError :message="errorFor('name')" />
            </div>

            <div class="grid gap-2">
              <Label for="provider">{{ t('存储提供商') }}</Label>
              <Input
                id="provider"
                :model-value="profileProviderLabel"
                class="bg-muted/40 text-muted-foreground"
                readonly
              />
              <p
                v-if="currentProvider?.help_link"
                class="text-xs text-muted-foreground"
              >
                <a
                  :href="currentProvider.help_link"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-primary hover:underline"
                >
                  {{
                    t('查看 {provider} 接入文档', {
                      provider: profileProviderLabel,
                    })
                  }}
                </a>
              </p>
              <InputError :message="errorFor('provider')" />
            </div>

            <div class="grid gap-2">
              <Label for="region">{{ t('区域 (Region)') }}</Label>
              <Input
                id="region"
                :model-value="profileRegionLabel"
                class="bg-muted/40 text-muted-foreground"
                readonly
              />
              <InputError :message="errorFor('region')" />
            </div>

            <div class="grid gap-2">
              <Label for="endpoint">{{ t('Endpoint 地址') }}</Label>
              <Input
                id="endpoint"
                :model-value="nullToEmpty(props.profile.endpoint)"
                type="url"
                class="bg-muted/40 text-muted-foreground"
                readonly
              />
              <InputError :message="errorFor('endpoint')" />
            </div>

            <div class="grid gap-2">
              <Label for="bucket">{{ t('Bucket 名称') }}</Label>
              <Input
                id="bucket"
                :model-value="nullToEmpty(props.profile.bucket)"
                class="bg-muted/40 text-muted-foreground"
                readonly
              />
              <InputError :message="errorFor('bucket')" />
            </div>

            <div class="grid gap-2">
              <Label for="key">
                {{ t('Access Key / Access Key ID') }}
              </Label>
              <Input id="key" v-model="editKey" autocomplete="off" />
              <InputError :message="errorFor('key')" />
            </div>

            <div class="grid gap-2">
              <Label for="secret">
                {{ t('Secret Key / Access Key Secret') }}
              </Label>
              <Input
                id="secret"
                v-model="editSecret"
                type="password"
                autocomplete="off"
              />
              <InputError :message="errorFor('secret')" />
            </div>

            <div class="grid gap-2">
              <Label for="url">{{ t('自定义域名 (可选)') }}</Label>
              <Input id="url" v-model="editUrl" type="url" />
              <p class="text-xs text-muted-foreground">
                {{
                  t(
                    '如果配置了 CDN 或自定义域名，请在此填写，用于生成文件访问 URL',
                  )
                }}
              </p>
              <InputError :message="errorFor('url')" />
            </div>

            <FormActions
              :submit-label="t('保存')"
              :processing="submitting"
              :cancel-href="admin.storage.show.url()"
            >
              <Button
                type="button"
                variant="outline"
                :disabled="submitting"
                @click="checkConnection"
              >
                <LoaderCircle
                  v-if="checkForm.processing"
                  class="mr-2 h-4 w-4 animate-spin"
                />
                {{ checkForm.processing ? t('检测中...') : t('检测连接') }}
              </Button>
            </FormActions>
          </form>
        </div>
      </div>
    </div>
  </SystemAppLayout>
</template>
