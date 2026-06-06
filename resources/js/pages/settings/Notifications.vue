<!--
  文件说明：个人通知设置页面，消费 ShowNotificationSettingsPagePropsData 并保存浏览器通知、声音和提醒范围偏好。
-->
<script setup lang="ts">
import UpdateNotificationSettingsAction from '@/actions/App/Actions/User/UpdateNotificationSettingsAction';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { showBrowserNotification } from '@/composables/useBrowserNotification';
import { useI18n } from '@/composables/useI18n';
import { playNotificationSound } from '@/composables/useNotificationSound';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import type {
  EnumOptionData,
  FormUpdateNotificationPreferencesData,
  NotificationSound,
  UserNotificationPreferencesData,
} from '@/types/generated';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{
  preferences: UserNotificationPreferencesData;
  sound_options: EnumOptionData[];
}>();

type BrowserPermission = NotificationPermission | 'unsupported';

const { t } = useI18n();
const page = usePage();
const permission = ref<BrowserPermission>('unsupported');

const RootLayout = computed(() =>
  page.props.auth.user.is_super_admin ? SystemAppLayout : AppLayout,
);
const form = useForm<FormUpdateNotificationPreferencesData>({
  browser_notifications_enabled:
    props.preferences.browser_notifications_enabled,
  sound_enabled: props.preferences.sound_enabled,
  sound: props.preferences.sound,
  notify_assigned_conversations:
    props.preferences.notify_assigned_conversations,
  notify_unassigned_conversations:
    props.preferences.notify_unassigned_conversations,
});

const permissionLabel = computed(
  () =>
    ({
      granted: t('浏览器已允许桌面通知'),
      denied: t('浏览器已拒绝桌面通知，请在浏览器设置中重新允许'),
      default: t('尚未请求浏览器通知权限'),
      unsupported: t('当前浏览器不支持桌面通知'),
    })[permission.value],
);

function syncPermission(): void {
  permission.value =
    typeof window !== 'undefined' && 'Notification' in window
      ? window.Notification.permission
      : 'unsupported';
}

async function requestPermission(): Promise<void> {
  if (typeof window === 'undefined' || !('Notification' in window)) {
    syncPermission();
    return;
  }

  permission.value = await window.Notification.requestPermission();
  if (permission.value === 'granted') {
    form.browser_notifications_enabled = true;
  }
}

function submit(): void {
  form.put(UpdateNotificationSettingsAction.url(), {
    preserveScroll: true,
  });
}

function testBrowserNotification(): void {
  showBrowserNotification({
    title: t('HelmDesk 通知测试'),
    body: t('桌面通知可以正常显示'),
  });
}

function updateSound(value: unknown): void {
  form.sound = String(value) as NotificationSound;
}

onMounted(syncPermission);
</script>

<template>
  <component :is="RootLayout">
    <Head :title="t('通知设置')" />

    <SettingsLayout>
      <form class="space-y-8" @submit.prevent="submit">
        <div class="space-y-6">
          <HeadingSmall
            :title="t('通知设置')"
            :description="t('控制客服工作台的新消息桌面通知和声音提醒')"
          />

          <section class="space-y-5">
            <div
              class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
            >
              <div class="min-w-0 space-y-1">
                <Label for="browser-notifications">
                  {{ t('桌面通知') }}
                </Label>
                <p class="text-sm text-muted-foreground">
                  {{ permissionLabel }}
                </p>
              </div>
              <div
                class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end"
              >
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  :disabled="permission === 'unsupported'"
                  @click="requestPermission"
                >
                  {{ t('请求浏览器权限') }}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  :disabled="permission !== 'granted'"
                  @click="testBrowserNotification"
                >
                  {{ t('试听桌面通知') }}
                </Button>
                <Switch
                  id="browser-notifications"
                  v-model="form.browser_notifications_enabled"
                  :disabled="
                    permission === 'unsupported' || permission === 'denied'
                  "
                />
              </div>
            </div>
            <InputError :message="form.errors.browser_notifications_enabled" />
          </section>

          <section class="space-y-5">
            <div
              class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
            >
              <div class="min-w-0 space-y-1">
                <Label for="sound-notifications">
                  {{ t('声音提醒') }}
                </Label>
                <p class="text-sm text-muted-foreground">
                  {{ t('新消息到达时播放选中的提示音') }}
                </p>
              </div>
              <div
                class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end"
              >
                <Select
                  :model-value="form.sound"
                  @update:model-value="updateSound"
                >
                  <SelectTrigger class="h-9 w-36">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem
                      v-for="option in props.sound_options"
                      :key="String(option.value)"
                      :value="String(option.value)"
                    >
                      {{ option.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  @click="playNotificationSound(form.sound)"
                >
                  {{ t('试听提示音') }}
                </Button>
                <Switch id="sound-notifications" v-model="form.sound_enabled" />
              </div>
            </div>
            <InputError :message="form.errors.sound_enabled" />
            <InputError :message="form.errors.sound" />
          </section>

          <section class="space-y-5">
            <HeadingSmall
              :title="t('提醒范围')"
              :description="t('选择哪些会话事件会触发桌面通知或声音')"
            />

            <div class="flex items-center justify-between gap-4">
              <div class="space-y-1">
                <Label for="notify-assigned">
                  {{ t('分配给我的会话') }}
                </Label>
                <p class="text-sm text-muted-foreground">
                  {{ t('访客新消息和会话转接给我时提醒') }}
                </p>
              </div>
              <Switch
                id="notify-assigned"
                v-model="form.notify_assigned_conversations"
              />
            </div>

            <div class="flex items-center justify-between gap-4">
              <div class="space-y-1">
                <Label for="notify-unassigned">
                  {{ t('待接入会话') }}
                </Label>
                <p class="text-sm text-muted-foreground">
                  {{ t('AI 转人工或访客请求人工时提醒') }}
                </p>
              </div>
              <Switch
                id="notify-unassigned"
                v-model="form.notify_unassigned_conversations"
              />
            </div>
            <InputError :message="form.errors.notify_assigned_conversations" />
            <InputError
              :message="form.errors.notify_unassigned_conversations"
            />
          </section>
        </div>

        <FormActions
          :submit-label="t('保存')"
          :processing="form.processing"
          submit-data-test="update-notification-settings-button"
        />
      </form>
    </SettingsLayout>
  </component>
</template>
