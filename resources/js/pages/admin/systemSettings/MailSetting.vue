<!--
  文件说明：系统邮箱服务器设置页面。当前只负责保存总后台单组邮件配置，
  并将配置应用到 Laravel 邮件运行时。
-->
<script setup lang="ts">
import SendMailSettingsTestEmailAction from '@/actions/App/Actions/SystemSetting/SendMailSettingsTestEmailAction';
import UpdateMailSettingsAction from '@/actions/App/Actions/SystemSetting/UpdateMailSettingsAction';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useI18n } from '@/composables/useI18n';
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type {
  FormSendMailSettingsTestEmailData,
  FormUpdateMailSettingData,
  ShowMailSettingsPagePropsData,
} from '@/types/generated';
import { Head, useForm } from '@inertiajs/vue3';
import { Trash2, Undo2 } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<ShowMailSettingsPagePropsData>();
const { t } = useI18n();

const form = useForm<FormUpdateMailSettingData>({
  enabled: props.settings.enabled,
  driver: props.settings.driver,
  from_address: props.settings.from_address ?? '',
  from_name: props.settings.from_name ?? '',
  smtp_host: props.settings.smtp_host ?? '',
  smtp_port: props.settings.smtp_port,
  smtp_encryption: props.settings.smtp_encryption,
  smtp_username: props.settings.smtp_username ?? '',
  smtp_password: '',
  clear_smtp_password: false,
  smtp_local_domain: props.settings.smtp_local_domain ?? '',
  smtp_timeout: props.settings.smtp_timeout,
  mailgun_domain: props.settings.mailgun_domain ?? '',
  mailgun_secret: '',
  clear_mailgun_secret: false,
  mailgun_endpoint: props.settings.mailgun_endpoint ?? '',
  mailgun_scheme: props.settings.mailgun_scheme,
  postmark_token: '',
  clear_postmark_token: false,
  postmark_message_stream_id: props.settings.postmark_message_stream_id ?? '',
  resend_key: '',
  clear_resend_key: false,
  ses_key: '',
  clear_ses_key: false,
  ses_secret: '',
  clear_ses_secret: false,
  ses_region: props.settings.ses_region ?? '',
  ses_token: '',
  clear_ses_token: false,
  sendmail_path: props.settings.sendmail_path ?? '',
});

const testForm = useForm<FormSendMailSettingsTestEmailData>({
  email: '',
});

const testMailDialogOpen = ref(false);

type SecretField =
  | 'smtp_password'
  | 'mailgun_secret'
  | 'postmark_token'
  | 'resend_key'
  | 'ses_key'
  | 'ses_secret'
  | 'ses_token';

type SecretClearField =
  | 'clear_smtp_password'
  | 'clear_mailgun_secret'
  | 'clear_postmark_token'
  | 'clear_resend_key'
  | 'clear_ses_key'
  | 'clear_ses_secret'
  | 'clear_ses_token';

const secretClearFields: Record<SecretField, SecretClearField> = {
  smtp_password: 'clear_smtp_password',
  mailgun_secret: 'clear_mailgun_secret',
  postmark_token: 'clear_postmark_token',
  resend_key: 'clear_resend_key',
  ses_key: 'clear_ses_key',
  ses_secret: 'clear_ses_secret',
  ses_token: 'clear_ses_token',
};

const isSmtp = computed(() => form.driver === 'smtp');
const isSendmail = computed(() => form.driver === 'sendmail');
const isMailgun = computed(() => form.driver === 'mailgun');
const isPostmark = computed(() => form.driver === 'postmark');
const isResend = computed(() => form.driver === 'resend');
const isSes = computed(() => form.driver === 'ses-v2');

const setSecret = (field: SecretField, value: string | number) => {
  (form as unknown as Record<SecretField, string>)[field] = String(value);
  (form as unknown as Record<SecretClearField, boolean>)[
    secretClearFields[field]
  ] = false;
};

const isSecretMarkedForClearing = (field: SecretField): boolean =>
  Boolean(
    (form as unknown as Record<SecretClearField, boolean>)[
      secretClearFields[field]
    ],
  );

const shouldShowSecretClearButton = (field: SecretField): boolean =>
  props.settings.existing_secrets[field] || isSecretMarkedForClearing(field);

const toggleSecretClear = (field: SecretField) => {
  const clearField = secretClearFields[field];
  const shouldClear = !isSecretMarkedForClearing(field);

  (form as unknown as Record<SecretClearField, boolean>)[clearField] =
    shouldClear;

  if (shouldClear) {
    (form as unknown as Record<SecretField, string>)[field] = '';
  }
};

const submit = () => {
  form.put(UpdateMailSettingsAction.url(), {
    preserveScroll: true,
    onSuccess: () => {
      form.smtp_password = '';
      form.clear_smtp_password = false;
      form.mailgun_secret = '';
      form.clear_mailgun_secret = false;
      form.postmark_token = '';
      form.clear_postmark_token = false;
      form.resend_key = '';
      form.clear_resend_key = false;
      form.ses_key = '';
      form.clear_ses_key = false;
      form.ses_secret = '';
      form.clear_ses_secret = false;
      form.ses_token = '';
      form.clear_ses_token = false;
    },
  });
};

const sendTestEmail = () => {
  testForm.post(SendMailSettingsTestEmailAction.url(), {
    preserveScroll: true,
    onSuccess: () => {
      testMailDialogOpen.value = false;
      testForm.reset();
    },
  });
};

const handleTestMailDialogOpen = (open: boolean) => {
  testMailDialogOpen.value = open;

  if (!open) {
    testForm.reset();
    testForm.clearErrors();
  }
};
</script>

<template>
  <SystemAppLayout>
    <Head :title="t('邮箱服务器')" />

    <SystemSettingsLayout>
      <div class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <HeadingSmall
            :title="t('邮箱服务器')"
            :description="t('配置系统事务邮件使用的发送驱动和发件身份。')"
          />

          <form class="space-y-6" @submit.prevent="submit">
            <div class="flex items-center justify-between gap-4">
              <div class="space-y-1">
                <Label>{{ t('启用系统邮件') }}</Label>
              </div>
              <Switch
                :model-value="form.enabled"
                :aria-label="t('启用系统邮件')"
                @update:model-value="
                  (checked) => (form.enabled = Boolean(checked))
                "
              />
            </div>

            <div class="space-y-6">
              <div class="grid gap-2">
                <Label for="driver">{{ t('邮件驱动') }}</Label>
                <Select v-model="form.driver" :default-value="form.driver">
                  <SelectTrigger id="driver" class="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem
                      v-for="driver in props.driver_options"
                      :key="String(driver.value)"
                      :value="String(driver.value)"
                    >
                      {{ driver.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
                <InputError :message="form.errors.driver" />
              </div>

              <div class="grid gap-2">
                <Label for="from_address">{{ t('发件邮箱') }}</Label>
                <Input
                  id="from_address"
                  type="email"
                  :model-value="form.from_address ?? ''"
                  autocomplete="off"
                  @update:model-value="
                    (value) => (form.from_address = String(value))
                  "
                />
                <InputError :message="form.errors.from_address" />
              </div>

              <div class="grid gap-2">
                <Label for="from_name">{{ t('发件人名称') }}</Label>
                <Input
                  id="from_name"
                  :model-value="form.from_name ?? ''"
                  autocomplete="off"
                  @update:model-value="
                    (value) => (form.from_name = String(value))
                  "
                />
                <InputError :message="form.errors.from_name" />
              </div>
            </div>

            <div class="space-y-6">
              <div v-if="isSmtp" class="space-y-6">
                <div class="grid gap-2">
                  <Label for="smtp_host">{{ t('SMTP 主机') }}</Label>
                  <Input
                    id="smtp_host"
                    :model-value="form.smtp_host ?? ''"
                    autocomplete="off"
                    @update:model-value="
                      (value) => (form.smtp_host = String(value))
                    "
                  />
                  <InputError :message="form.errors.smtp_host" />
                </div>

                <div class="grid gap-2">
                  <Label for="smtp_port">{{ t('SMTP 端口') }}</Label>
                  <Input
                    id="smtp_port"
                    type="number"
                    min="1"
                    max="65535"
                    :model-value="form.smtp_port ?? ''"
                    @update:model-value="
                      (value) =>
                        (form.smtp_port =
                          String(value).trim() === '' ? null : Number(value))
                    "
                  />
                  <InputError :message="form.errors.smtp_port" />
                </div>

                <div class="grid gap-2">
                  <Label for="smtp_encryption">{{ t('加密方式') }}</Label>
                  <Select
                    :model-value="form.smtp_encryption ?? 'starttls'"
                    @update:model-value="
                      (value) => (form.smtp_encryption = String(value))
                    "
                  >
                    <SelectTrigger id="smtp_encryption" class="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="starttls">STARTTLS</SelectItem>
                      <SelectItem value="ssl">SSL/TLS</SelectItem>
                      <SelectItem value="none">{{ t('无') }}</SelectItem>
                    </SelectContent>
                  </Select>
                  <InputError :message="form.errors.smtp_encryption" />
                </div>

                <div class="grid gap-2">
                  <Label for="smtp_timeout">{{ t('超时时间（秒）') }}</Label>
                  <Input
                    id="smtp_timeout"
                    type="number"
                    min="1"
                    max="120"
                    :model-value="form.smtp_timeout ?? ''"
                    @update:model-value="
                      (value) =>
                        (form.smtp_timeout =
                          String(value).trim() === '' ? null : Number(value))
                    "
                  />
                  <InputError :message="form.errors.smtp_timeout" />
                </div>

                <div class="grid gap-2">
                  <Label for="smtp_username">{{ t('用户名') }}</Label>
                  <Input
                    id="smtp_username"
                    :model-value="form.smtp_username ?? ''"
                    autocomplete="off"
                    @update:model-value="
                      (value) => (form.smtp_username = String(value))
                    "
                  />
                  <InputError :message="form.errors.smtp_username" />
                </div>

                <div class="grid gap-2">
                  <Label for="smtp_password">{{ t('密码') }}</Label>
                  <div class="relative">
                    <Input
                      id="smtp_password"
                      type="password"
                      class="pr-10"
                      :model-value="form.smtp_password ?? ''"
                      autocomplete="new-password"
                      @update:model-value="
                        (value) => setSecret('smtp_password', value)
                      "
                    />
                    <Button
                      v-if="shouldShowSecretClearButton('smtp_password')"
                      type="button"
                      variant="ghost"
                      size="icon"
                      class="absolute top-1/2 right-1 h-7 w-7 -translate-y-1/2"
                      :aria-label="
                        isSecretMarkedForClearing('smtp_password')
                          ? t('取消清除')
                          : t('清除')
                      "
                      :title="
                        isSecretMarkedForClearing('smtp_password')
                          ? t('取消清除')
                          : t('清除')
                      "
                      @click="toggleSecretClear('smtp_password')"
                    >
                      <Undo2
                        v-if="isSecretMarkedForClearing('smtp_password')"
                        class="h-4 w-4"
                      />
                      <Trash2 v-else class="h-4 w-4" />
                    </Button>
                  </div>
                  <InputError :message="form.errors.smtp_password" />
                </div>

                <div class="grid gap-2">
                  <Label for="smtp_local_domain">{{ t('EHLO 域名') }}</Label>
                  <Input
                    id="smtp_local_domain"
                    :model-value="form.smtp_local_domain ?? ''"
                    autocomplete="off"
                    @update:model-value="
                      (value) => (form.smtp_local_domain = String(value))
                    "
                  />
                  <InputError :message="form.errors.smtp_local_domain" />
                </div>
              </div>

              <div v-if="isSendmail" class="grid gap-2">
                <Label for="sendmail_path">{{ t('Sendmail 路径') }}</Label>
                <Input
                  id="sendmail_path"
                  :model-value="form.sendmail_path ?? ''"
                  autocomplete="off"
                  @update:model-value="
                    (value) => (form.sendmail_path = String(value))
                  "
                />
                <InputError :message="form.errors.sendmail_path" />
              </div>

              <div v-if="isMailgun" class="space-y-6">
                <div class="grid gap-2">
                  <Label for="mailgun_domain">{{ t('Mailgun 域名') }}</Label>
                  <Input
                    id="mailgun_domain"
                    :model-value="form.mailgun_domain ?? ''"
                    autocomplete="off"
                    @update:model-value="
                      (value) => (form.mailgun_domain = String(value))
                    "
                  />
                  <InputError :message="form.errors.mailgun_domain" />
                </div>

                <div class="grid gap-2">
                  <Label for="mailgun_secret">{{ t('Mailgun 密钥') }}</Label>
                  <div class="relative">
                    <Input
                      id="mailgun_secret"
                      type="password"
                      class="pr-10"
                      :model-value="form.mailgun_secret ?? ''"
                      autocomplete="new-password"
                      @update:model-value="
                        (value) => setSecret('mailgun_secret', value)
                      "
                    />
                    <Button
                      v-if="shouldShowSecretClearButton('mailgun_secret')"
                      type="button"
                      variant="ghost"
                      size="icon"
                      class="absolute top-1/2 right-1 h-7 w-7 -translate-y-1/2"
                      :aria-label="
                        isSecretMarkedForClearing('mailgun_secret')
                          ? t('取消清除')
                          : t('清除')
                      "
                      :title="
                        isSecretMarkedForClearing('mailgun_secret')
                          ? t('取消清除')
                          : t('清除')
                      "
                      @click="toggleSecretClear('mailgun_secret')"
                    >
                      <Undo2
                        v-if="isSecretMarkedForClearing('mailgun_secret')"
                        class="h-4 w-4"
                      />
                      <Trash2 v-else class="h-4 w-4" />
                    </Button>
                  </div>
                  <InputError :message="form.errors.mailgun_secret" />
                </div>

                <div class="grid gap-2">
                  <Label for="mailgun_endpoint">{{
                    t('Mailgun 接口地址')
                  }}</Label>
                  <Input
                    id="mailgun_endpoint"
                    :model-value="form.mailgun_endpoint ?? ''"
                    autocomplete="off"
                    @update:model-value="
                      (value) => (form.mailgun_endpoint = String(value))
                    "
                  />
                  <InputError :message="form.errors.mailgun_endpoint" />
                </div>

                <div class="grid gap-2">
                  <Label for="mailgun_scheme">{{ t('请求协议') }}</Label>
                  <Select
                    :model-value="form.mailgun_scheme ?? 'https'"
                    @update:model-value="
                      (value) => (form.mailgun_scheme = String(value))
                    "
                  >
                    <SelectTrigger id="mailgun_scheme" class="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="https">HTTPS</SelectItem>
                      <SelectItem value="http">HTTP</SelectItem>
                    </SelectContent>
                  </Select>
                  <InputError :message="form.errors.mailgun_scheme" />
                </div>
              </div>

              <div v-if="isPostmark" class="space-y-6">
                <div class="grid gap-2">
                  <Label for="postmark_token">{{ t('Postmark Token') }}</Label>
                  <div class="relative">
                    <Input
                      id="postmark_token"
                      type="password"
                      class="pr-10"
                      :model-value="form.postmark_token ?? ''"
                      autocomplete="new-password"
                      @update:model-value="
                        (value) => setSecret('postmark_token', value)
                      "
                    />
                    <Button
                      v-if="shouldShowSecretClearButton('postmark_token')"
                      type="button"
                      variant="ghost"
                      size="icon"
                      class="absolute top-1/2 right-1 h-7 w-7 -translate-y-1/2"
                      :aria-label="
                        isSecretMarkedForClearing('postmark_token')
                          ? t('取消清除')
                          : t('清除')
                      "
                      :title="
                        isSecretMarkedForClearing('postmark_token')
                          ? t('取消清除')
                          : t('清除')
                      "
                      @click="toggleSecretClear('postmark_token')"
                    >
                      <Undo2
                        v-if="isSecretMarkedForClearing('postmark_token')"
                        class="h-4 w-4"
                      />
                      <Trash2 v-else class="h-4 w-4" />
                    </Button>
                  </div>
                  <InputError :message="form.errors.postmark_token" />
                </div>

                <div class="grid gap-2">
                  <Label for="postmark_message_stream_id">
                    {{ t('消息流') }}
                  </Label>
                  <Input
                    id="postmark_message_stream_id"
                    :model-value="form.postmark_message_stream_id ?? ''"
                    autocomplete="off"
                    @update:model-value="
                      (value) =>
                        (form.postmark_message_stream_id = String(value))
                    "
                  />
                  <InputError
                    :message="form.errors.postmark_message_stream_id"
                  />
                </div>
              </div>

              <div v-if="isResend" class="grid gap-2">
                <Label for="resend_key">{{ t('Resend API Key') }}</Label>
                <div class="relative">
                  <Input
                    id="resend_key"
                    type="password"
                    class="pr-10"
                    :model-value="form.resend_key ?? ''"
                    autocomplete="new-password"
                    @update:model-value="
                      (value) => setSecret('resend_key', value)
                    "
                  />
                  <Button
                    v-if="shouldShowSecretClearButton('resend_key')"
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="absolute top-1/2 right-1 h-7 w-7 -translate-y-1/2"
                    :aria-label="
                      isSecretMarkedForClearing('resend_key')
                        ? t('取消清除')
                        : t('清除')
                    "
                    :title="
                      isSecretMarkedForClearing('resend_key')
                        ? t('取消清除')
                        : t('清除')
                    "
                    @click="toggleSecretClear('resend_key')"
                  >
                    <Undo2
                      v-if="isSecretMarkedForClearing('resend_key')"
                      class="h-4 w-4"
                    />
                    <Trash2 v-else class="h-4 w-4" />
                  </Button>
                </div>
                <InputError :message="form.errors.resend_key" />
              </div>

              <div v-if="isSes" class="space-y-6">
                <div class="grid gap-2">
                  <Label for="ses_key">{{ t('Access Key ID') }}</Label>
                  <div class="relative">
                    <Input
                      id="ses_key"
                      type="password"
                      class="pr-10"
                      :model-value="form.ses_key ?? ''"
                      autocomplete="new-password"
                      @update:model-value="
                        (value) => setSecret('ses_key', value)
                      "
                    />
                    <Button
                      v-if="shouldShowSecretClearButton('ses_key')"
                      type="button"
                      variant="ghost"
                      size="icon"
                      class="absolute top-1/2 right-1 h-7 w-7 -translate-y-1/2"
                      :aria-label="
                        isSecretMarkedForClearing('ses_key')
                          ? t('取消清除')
                          : t('清除')
                      "
                      :title="
                        isSecretMarkedForClearing('ses_key')
                          ? t('取消清除')
                          : t('清除')
                      "
                      @click="toggleSecretClear('ses_key')"
                    >
                      <Undo2
                        v-if="isSecretMarkedForClearing('ses_key')"
                        class="h-4 w-4"
                      />
                      <Trash2 v-else class="h-4 w-4" />
                    </Button>
                  </div>
                  <InputError :message="form.errors.ses_key" />
                </div>

                <div class="grid gap-2">
                  <Label for="ses_secret">{{ t('Secret Access Key') }}</Label>
                  <div class="relative">
                    <Input
                      id="ses_secret"
                      type="password"
                      class="pr-10"
                      :model-value="form.ses_secret ?? ''"
                      autocomplete="new-password"
                      @update:model-value="
                        (value) => setSecret('ses_secret', value)
                      "
                    />
                    <Button
                      v-if="shouldShowSecretClearButton('ses_secret')"
                      type="button"
                      variant="ghost"
                      size="icon"
                      class="absolute top-1/2 right-1 h-7 w-7 -translate-y-1/2"
                      :aria-label="
                        isSecretMarkedForClearing('ses_secret')
                          ? t('取消清除')
                          : t('清除')
                      "
                      :title="
                        isSecretMarkedForClearing('ses_secret')
                          ? t('取消清除')
                          : t('清除')
                      "
                      @click="toggleSecretClear('ses_secret')"
                    >
                      <Undo2
                        v-if="isSecretMarkedForClearing('ses_secret')"
                        class="h-4 w-4"
                      />
                      <Trash2 v-else class="h-4 w-4" />
                    </Button>
                  </div>
                  <InputError :message="form.errors.ses_secret" />
                </div>

                <div class="grid gap-2">
                  <Label for="ses_region">{{ t('区域') }}</Label>
                  <Input
                    id="ses_region"
                    :model-value="form.ses_region ?? ''"
                    autocomplete="off"
                    @update:model-value="
                      (value) => (form.ses_region = String(value))
                    "
                  />
                  <InputError :message="form.errors.ses_region" />
                </div>

                <div class="grid gap-2">
                  <Label for="ses_token">{{ t('Session Token') }}</Label>
                  <div class="relative">
                    <Input
                      id="ses_token"
                      type="password"
                      class="pr-10"
                      :model-value="form.ses_token ?? ''"
                      autocomplete="new-password"
                      @update:model-value="
                        (value) => setSecret('ses_token', value)
                      "
                    />
                    <Button
                      v-if="shouldShowSecretClearButton('ses_token')"
                      type="button"
                      variant="ghost"
                      size="icon"
                      class="absolute top-1/2 right-1 h-7 w-7 -translate-y-1/2"
                      :aria-label="
                        isSecretMarkedForClearing('ses_token')
                          ? t('取消清除')
                          : t('清除')
                      "
                      :title="
                        isSecretMarkedForClearing('ses_token')
                          ? t('取消清除')
                          : t('清除')
                      "
                      @click="toggleSecretClear('ses_token')"
                    >
                      <Undo2
                        v-if="isSecretMarkedForClearing('ses_token')"
                        class="h-4 w-4"
                      />
                      <Trash2 v-else class="h-4 w-4" />
                    </Button>
                  </div>
                  <InputError :message="form.errors.ses_token" />
                </div>
              </div>
            </div>

            <FormActions
              :submit-label="t('保存')"
              :processing="form.processing"
            >
              <Button
                type="button"
                variant="secondary"
                @click="testMailDialogOpen = true"
              >
                {{ t('测试') }}
              </Button>
            </FormActions>
          </form>
        </div>
      </div>
    </SystemSettingsLayout>

    <Dialog :open="testMailDialogOpen" @update:open="handleTestMailDialogOpen">
      <DialogContent class="sm:max-w-md">
        <DialogHeader class="space-y-3">
          <DialogTitle>{{ t('发送测试邮件') }}</DialogTitle>
        </DialogHeader>

        <form class="space-y-6" @submit.prevent="sendTestEmail">
          <div class="grid gap-2">
            <Label for="test_email">{{ t('测试收件邮箱') }}</Label>
            <Input
              id="test_email"
              type="email"
              :model-value="testForm.email"
              autocomplete="off"
              @update:model-value="(value) => (testForm.email = String(value))"
            />
            <InputError :message="testForm.errors.email" />
          </div>

          <DialogFooter class="gap-2">
            <DialogClose as-child>
              <Button type="button" variant="secondary">
                {{ t('取消') }}
              </Button>
            </DialogClose>
            <Button type="submit" :disabled="testForm.processing">
              {{ t('发送测试邮件') }}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  </SystemAppLayout>
</template>
