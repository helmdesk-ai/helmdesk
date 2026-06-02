<!--
  文件说明：系统存储配置创建页面，使用普通平铺表单布局，
  并提供检测连接动作（不强制通过即可保存）。
-->
<script setup lang="ts">
import CheckStorageSettingAction from '@/actions/App/Actions/StorageSetting/CheckStorageSettingAction';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
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
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import admin from '@/routes/admin';
import storageProfile from '@/routes/admin/storage/profiles';
import type {
  FormCheckStorageSettingData,
  FormCreateStorageProfileData,
  ShowCreateStorageProfilePagePropsData,
} from '@/types/generated';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<ShowCreateStorageProfilePagePropsData>();
const { t } = useI18n();

const nullToEmpty = (value: string | null | undefined): string => value ?? '';
const emptyToNull = (value: string): string | null =>
  value === '' ? null : value;

const defaultProvider = computed<string>(() =>
  String(props.providers[0]?.provider.value ?? 'aws'),
);

const form = useForm<FormCreateStorageProfileData>({
  name: '',
  provider: defaultProvider.value,
  region: '',
  endpoint: '',
  bucket: '',
  key: '',
  secret: '',
  url: null,
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

const useInternalEndpoint = ref(false);

const customUrl = computed<string>({
  get: () => nullToEmpty(form.url),
  set: (value) => {
    form.url = emptyToNull(value);
  },
});

const currentProvider = computed(() =>
  props.providers.find((p) => p.provider.value === form.provider),
);

const currentRegions = computed(() => currentProvider.value?.regions ?? []);

const currentRegionData = computed(() =>
  currentRegions.value.find((r) => r.id === form.region),
);

const isAliyun = computed(() => form.provider === 'aliyun');

const hasInternalEndpoint = computed(
  () => isAliyun.value && Boolean(currentRegionData.value?.internal_endpoint),
);

watch(
  () => form.provider,
  () => {
    form.region = '';
    form.endpoint = '';
    useInternalEndpoint.value = false;
  },
);

watch(
  () => form.region,
  (newRegion) => {
    const region = currentRegions.value.find((r) => r.id === newRegion);
    if (region) {
      form.endpoint = region.endpoint;
      useInternalEndpoint.value = false;
    }
  },
);

const toggleInternalEndpoint = () => {
  if (!currentRegionData.value) {
    return;
  }

  if (useInternalEndpoint.value) {
    form.endpoint = currentRegionData.value.endpoint;
    useInternalEndpoint.value = false;

    return;
  }

  form.endpoint =
    currentRegionData.value.internal_endpoint ??
    currentRegionData.value.endpoint;
  useInternalEndpoint.value = true;
};

const errorFor = (field: string): string | undefined =>
  (form.errors as Record<string, string | undefined>)[field] ??
  (checkForm.errors as Record<string, string | undefined>)[field];

const submitting = computed(() => form.processing || checkForm.processing);

const checkConnection = () => {
  checkForm.clearErrors();
  Object.assign(checkForm, {
    provider: form.provider,
    region: form.region,
    endpoint: form.endpoint,
    key: form.key,
    secret: form.secret,
    bucket: form.bucket,
    url: form.url,
  });

  checkForm.put(CheckStorageSettingAction.url(), {
    preserveScroll: true,
  });
};

const submit = () => {
  form.post(storageProfile.create.url(), {
    preserveScroll: true,
  });
};
</script>

<template>
  <SystemAppLayout>
    <Head :title="t('新增存储配置')" />

    <SystemSettingsLayout>
      <div class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <HeadingSmall
            :title="t('新增存储配置')"
            :description="
              t('填写对象存储凭据；可先点检测连接确认无误后再保存。')
            "
          />

          <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
              <Label for="name">{{ t('配置名称') }}</Label>
              <Input id="name" v-model="form.name" autocomplete="off" />
              <InputError :message="errorFor('name')" />
            </div>

            <div class="grid gap-2">
              <Label for="provider">{{ t('存储提供商') }}</Label>
              <Select v-model="form.provider" :default-value="form.provider">
                <SelectTrigger id="provider">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem
                    v-for="providerOption in providers"
                    :key="providerOption.provider.value"
                    :value="providerOption.provider.value"
                  >
                    {{ providerOption.provider.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
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
                      provider: currentProvider.provider.label,
                    })
                  }}
                </a>
              </p>
              <InputError :message="errorFor('provider')" />
            </div>

            <div class="grid gap-2">
              <Label for="region">{{ t('区域 (Region)') }}</Label>
              <Select v-model="form.region" :default-value="form.region">
                <SelectTrigger id="region">
                  <template v-if="currentRegionData">
                    <div class="flex items-baseline gap-2">
                      <span class="text-sm">{{ currentRegionData.name }}</span>
                      <span class="font-mono text-xs text-muted-foreground">
                        {{ currentRegionData.id }}
                      </span>
                    </div>
                  </template>
                  <SelectValue v-else />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem
                    v-for="regionOption in currentRegions"
                    :key="regionOption.id"
                    :value="regionOption.id"
                  >
                    <div class="flex items-baseline gap-2">
                      <span class="text-sm">{{ regionOption.name }}</span>
                      <span class="font-mono text-xs text-muted-foreground">
                        {{ regionOption.id }}
                      </span>
                    </div>
                  </SelectItem>
                </SelectContent>
              </Select>
              <InputError :message="errorFor('region')" />
            </div>

            <div class="grid gap-2">
              <div class="flex items-center justify-between">
                <Label for="endpoint">{{ t('Endpoint 地址') }}</Label>
                <Button
                  v-if="hasInternalEndpoint"
                  type="button"
                  variant="outline"
                  size="sm"
                  @click="toggleInternalEndpoint"
                >
                  {{
                    useInternalEndpoint
                      ? t('使用外网 Endpoint')
                      : t('使用内网 Endpoint')
                  }}
                </Button>
              </div>
              <Input id="endpoint" v-model="form.endpoint" type="url" />
              <p
                v-if="hasInternalEndpoint"
                class="text-xs text-muted-foreground"
              >
                {{
                  t(
                    '如果服务器和对象存储在同一区域，建议使用内网 Endpoint 以提高速度并节省流量费用',
                  )
                }}
              </p>
              <InputError :message="errorFor('endpoint')" />
            </div>

            <div class="grid gap-2">
              <Label for="bucket">{{ t('Bucket 名称') }}</Label>
              <Input id="bucket" v-model="form.bucket" />
              <InputError :message="errorFor('bucket')" />
            </div>

            <div class="grid gap-2">
              <Label for="key">
                {{ t('Access Key / Access Key ID') }}
              </Label>
              <Input id="key" v-model="form.key" autocomplete="off" />
              <InputError :message="errorFor('key')" />
            </div>

            <div class="grid gap-2">
              <Label for="secret">
                {{ t('Secret Key / Access Key Secret') }}
              </Label>
              <Input
                id="secret"
                v-model="form.secret"
                type="password"
                autocomplete="off"
              />
              <InputError :message="errorFor('secret')" />
            </div>

            <div class="grid gap-2">
              <Label for="url">{{ t('自定义域名 (可选)') }}</Label>
              <Input id="url" v-model="customUrl" type="url" />
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
              :submit-label="t('创建')"
              :processing="submitting"
              :cancel-href="admin.storage.show.url()"
            >
              <template #submit>
                <LoaderCircle
                  v-if="form.processing"
                  class="mr-2 h-4 w-4 animate-spin"
                />
                {{ form.processing ? t('创建中...') : t('创建') }}
              </template>
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
    </SystemSettingsLayout>
  </SystemAppLayout>
</template>
