<!--
  文件说明：网站渠道入口与设备配置标签页，承接默认气泡/自定义入口、PC 贴边位置、提醒和移动端全屏行为。
-->
<script setup lang="ts">
import Web from '@/actions/App/Actions/Channel/Web';
import FormActions from '@/components/common/FormActions.vue';
import ImageUploadField from '@/components/common/ImageUploadField.vue';
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
import { Switch } from '@/components/ui/switch';
import { useChannelPreviewDraft } from '@/composables/useChannelPreviewDraft';
import { useI18n } from '@/composables/useI18n';
import type {
  WebChannelData,
  WebChannelFormOptionsData,
} from '@/types/generated';
import { Form } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';

const props = defineProps<{
  channel: WebChannelData;
  formOptions: WebChannelFormOptionsData;
}>();

type EntryMode = WebChannelData['widget']['entry']['mode'];
type EntryPosition = WebChannelData['widget']['entry']['position'];
type EntryStyle = WebChannelData['widget']['entry']['style'];
type EntryIconSize = WebChannelData['widget']['entry']['icon_size'];
type SelectModelValue =
  | string
  | number
  | bigint
  | Record<string, unknown>
  | null;

const { t } = useI18n();
const draft = useChannelPreviewDraft();

const entryMode = ref<EntryMode>(props.channel.widget.entry.mode);
const entryPosition = ref<EntryPosition>(props.channel.widget.entry.position);
const entryStyle = ref<EntryStyle>(props.channel.widget.entry.style);
const entryIconSize = ref<EntryIconSize>(props.channel.widget.entry.icon_size);
const entryBottomOffset = ref(props.channel.widget.entry.bottom_offset);
const unreadBadgeEnabled = ref(props.channel.widget.unread_badge_enabled);
const inlineToastEnabled = ref(props.channel.widget.inline_toast_enabled);
const mobileFullscreenEnabled = ref(
  props.channel.widget.mobile_fullscreen_enabled,
);
// 自定义入口图标的预览地址：来自已保存数据或刚上传的回传，驱动右侧实时预览。
const entryDefaultIconPreview = ref(
  props.channel.widget.entry.default_icon_url ?? '',
);
const entrySelectedIconPreview = ref(
  props.channel.widget.entry.active_icon_url ?? '',
);

const usesDefaultBubble = computed(() => entryMode.value === 'bubble');
const usesCustomIconStyle = computed(
  () => usesDefaultBubble.value && entryStyle.value === 'custom',
);
const declarativeTriggerSnippet = `<button data-helmdesk-open>${t('联系客服')}</button>`;

// 入口与设备配置同步进预览草稿，驱动右侧小部件形态示意。
watchEffect(() => {
  draft.entryMode = entryMode.value;
  draft.entryPosition = entryPosition.value;
  draft.entryStyle = entryStyle.value;
  draft.entryIconSize = entryIconSize.value;
  draft.entryBottomOffset = entryBottomOffset.value;
  draft.mobileFullscreenEnabled = mobileFullscreenEnabled.value;
  draft.entryDefaultIconUrl = usesCustomIconStyle.value
    ? entryDefaultIconPreview.value || null
    : null;
  draft.entrySelectedIconUrl = usesCustomIconStyle.value
    ? entrySelectedIconPreview.value || null
    : null;
});

const updateEntryMode = (value: SelectModelValue) => {
  if (typeof value !== 'string') {
    return;
  }

  entryMode.value = value as EntryMode;
};

const updateEntryPosition = (value: SelectModelValue) => {
  if (typeof value !== 'string') {
    return;
  }

  entryPosition.value = value as EntryPosition;
};

const updateEntryStyle = (value: SelectModelValue) => {
  if (typeof value !== 'string') {
    return;
  }

  entryStyle.value = value as EntryStyle;
};

const updateEntryIconSize = (value: SelectModelValue) => {
  if (typeof value !== 'string') {
    return;
  }

  entryIconSize.value = value as EntryIconSize;
};

const updateEntryBottomOffset = (value: string | number) => {
  const number = Number(value);

  if (!Number.isFinite(number)) {
    return;
  }

  entryBottomOffset.value = number;
};
</script>

<template>
  <Form
    :action="
      Web.UpdateWebChannelWidgetAction.url({
        channel: props.channel.id,
      })
    "
    method="put"
    class="space-y-6"
  >
    <template #default="{ errors, processing }">
      <input type="hidden" name="entry_mode" :value="entryMode" />
      <input type="hidden" name="entry_position" :value="entryPosition" />
      <input type="hidden" name="entry_style" :value="entryStyle" />
      <input type="hidden" name="entry_icon_size" :value="entryIconSize" />
      <input
        type="hidden"
        name="entry_bottom_offset"
        :value="entryBottomOffset"
      />
      <input
        type="hidden"
        name="unread_badge_enabled"
        :value="usesDefaultBubble && unreadBadgeEnabled ? '1' : '0'"
      />
      <input
        type="hidden"
        name="inline_toast_enabled"
        :value="usesDefaultBubble && inlineToastEnabled ? '1' : '0'"
      />
      <input
        type="hidden"
        name="mobile_fullscreen_enabled"
        :value="mobileFullscreenEnabled ? '1' : '0'"
      />

      <div class="max-w-2xl space-y-8">
        <section class="space-y-5">
          <div class="grid gap-2">
            <Label for="widget_entry_mode" required>
              {{ t('入口模式') }}
            </Label>
            <Select
              :model-value="entryMode"
              @update:model-value="updateEntryMode"
            >
              <SelectTrigger id="widget_entry_mode" class="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in props.formOptions.widget_entry_mode_options"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <InputError :message="errors.entry_mode" />
          </div>

          <div v-if="!usesDefaultBubble" class="space-y-3 text-sm">
            <p class="text-muted-foreground">
              {{
                t(
                  '自定义入口会隐藏 HelmDesk 默认气泡，由你网站上的按钮或脚本主动打开聊天窗口。',
                )
              }}
            </p>
            <pre
              class="rounded-md border bg-muted/30 p-3 break-all whitespace-pre-wrap"
              >{{ declarativeTriggerSnippet }}</pre
            >
            <p class="text-muted-foreground">
              {{
                t(
                  '也可以在你的点击事件中调用 HelmDesk.show()；多渠道页面可使用 HelmDesk.channels[code].show()。',
                )
              }}
            </p>
          </div>
        </section>

        <section class="space-y-5">
          <div class="grid gap-2">
            <Label for="widget_entry_position" required>
              {{ usesDefaultBubble ? t('入口位置') : t('聊天窗位置') }}
            </Label>
            <Select
              :model-value="entryPosition"
              @update:model-value="updateEntryPosition"
            >
              <SelectTrigger id="widget_entry_position" class="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in props.formOptions
                    .widget_entry_position_options"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <InputError :message="errors.entry_position" />
          </div>

          <template v-if="usesDefaultBubble">
            <div class="grid gap-2">
              <Label for="widget_entry_style" required>
                {{ t('入口样式') }}
              </Label>
              <Select
                :model-value="entryStyle"
                @update:model-value="updateEntryStyle"
              >
                <SelectTrigger id="widget_entry_style" class="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem
                    v-for="option in props.formOptions
                      .widget_entry_style_options"
                    :key="option.value"
                    :value="option.value"
                  >
                    {{ option.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
              <InputError :message="errors.entry_style" />
            </div>

            <div class="grid gap-2">
              <Label for="widget_entry_icon_size" required>
                {{ t('入口图标大小') }}
              </Label>
              <Select
                :model-value="entryIconSize"
                @update:model-value="updateEntryIconSize"
              >
                <SelectTrigger id="widget_entry_icon_size" class="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem
                    v-for="option in props.formOptions.widget_icon_size_options"
                    :key="option.value"
                    :value="option.value"
                  >
                    {{ option.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
              <InputError :message="errors.entry_icon_size" />
            </div>

            <div class="grid gap-2">
              <Label for="widget_entry_bottom_offset" required>
                {{ t('入口底部间距') }}
              </Label>
              <Input
                id="widget_entry_bottom_offset"
                type="number"
                min="0"
                max="120"
                :model-value="entryBottomOffset"
                @update:model-value="updateEntryBottomOffset"
              />
              <InputError :message="errors.entry_bottom_offset" />
            </div>

            <div v-show="entryStyle === 'custom'" class="grid gap-4">
              <ImageUploadField
                :label="t('默认图标')"
                name="entry_default_icon_id"
                purpose="channel_icon"
                variant="logo"
                :initial-preview="
                  props.channel.widget.entry.default_icon_url ?? ''
                "
                :initial-value="
                  props.channel.widget.entry.default_icon_id ?? ''
                "
                :help-text="t('不上传则入口使用系统默认图标。')"
                :error="errors.entry_default_icon_id"
                input-id="widget_entry_default_icon"
                @update:preview="entryDefaultIconPreview = $event"
              />

              <ImageUploadField
                :label="t('选中图标')"
                name="entry_active_icon_id"
                purpose="channel_icon"
                variant="logo"
                :initial-preview="
                  props.channel.widget.entry.active_icon_url ?? ''
                "
                :initial-value="props.channel.widget.entry.active_icon_id ?? ''"
                :help-text="
                  t('展开聊天后入口显示的图标，需与默认图标成对上传。')
                "
                :error="errors.entry_active_icon_id"
                input-id="widget_entry_active_icon"
                @update:preview="entrySelectedIconPreview = $event"
              />
            </div>
          </template>
        </section>

        <section class="space-y-5">
          <div class="flex items-center justify-between gap-4">
            <div class="space-y-1">
              <Label>{{ t('移动端展开后铺满屏幕') }}</Label>
              <p class="text-sm text-muted-foreground">
                {{
                  t(
                    '开启后，小部件在手机浏览器中打开聊天时会接管整个屏幕，避免键盘和页面滚动挤压聊天区。',
                  )
                }}
              </p>
            </div>
            <Switch v-model="mobileFullscreenEnabled" />
          </div>
        </section>

        <section v-if="usesDefaultBubble" class="space-y-5">
          <div class="flex items-center justify-between gap-4">
            <div class="space-y-1">
              <Label>{{ t('显示未读角标') }}</Label>
              <p class="text-sm text-muted-foreground">
                {{ t('入口右上角小红点，提示访客有新消息。') }}
              </p>
            </div>
            <Switch v-model="unreadBadgeEnabled" />
          </div>
          <div class="flex items-center justify-between gap-4">
            <div class="space-y-1">
              <Label>{{ t('显示提示弹窗') }}</Label>
              <p class="text-sm text-muted-foreground">
                {{
                  t(
                    '在入口附近弹出新消息预览，点击展开聊天。打扰较强，默认关闭。',
                  )
                }}
              </p>
            </div>
            <Switch v-model="inlineToastEnabled" />
          </div>
        </section>

        <FormActions :submit-label="t('保存')" :processing="processing" />
      </div>
    </template>
  </Form>
</template>
