<!--
  文件说明：网站渠道访客界面配置标签页，承接独立页和小部件共用的标题栏、客服身份、欢迎语、主题色与首页模式。
  字段直接读写贯穿详情页的预览草稿，编辑即时反映到右侧常驻实时预览。
-->
<script setup lang="ts">
import Web from '@/actions/App/Actions/Channel/Web';
import FormActions from '@/components/common/FormActions.vue';
import ImageUploadField from '@/components/common/ImageUploadField.vue';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useChannelPreviewDraft } from '@/composables/useChannelPreviewDraft';
import { useI18n } from '@/composables/useI18n';
import type {
  WebChannelData,
  WebChannelFormOptionsData,
} from '@/types/generated';
import { Form } from '@inertiajs/vue3';
import { Check, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
  channel: WebChannelData;
  formOptions: WebChannelFormOptionsData;
}>();

const { t } = useI18n();
const draft = useChannelPreviewDraft();

// 后端 list<string> 序列化为以数字为键的对象，统一取 values 渲染色板。
const themeColorOptions = computed<string[]>(() =>
  Object.values(props.formOptions.theme_color_options),
);

const HOME_WELCOME_MAX_LENGTH = 50;

// 猜你想问：编辑行可含空白占位，归一化后随表单提交并同步进预览草稿。
const MAX_SUGGESTION_ITEMS = 6;
const suggestionItems = ref<string[]>(
  props.channel.suggestions.items.length > 0
    ? [...props.channel.suggestions.items]
    : [''],
);
const normalizedSuggestionItems = computed(() =>
  suggestionItems.value
    .map((item) => item.trim())
    .filter(Boolean)
    .slice(0, MAX_SUGGESTION_ITEMS),
);
watch(
  normalizedSuggestionItems,
  (items) => {
    draft.suggestionItems = items;
  },
  { immediate: true },
);
const addSuggestion = () => {
  if (suggestionItems.value.length >= MAX_SUGGESTION_ITEMS) {
    return;
  }
  suggestionItems.value.push('');
};
const removeSuggestion = (index: number) => {
  suggestionItems.value.splice(index, 1);
  if (suggestionItems.value.length === 0) {
    suggestionItems.value.push('');
  }
};
</script>

<template>
  <Form
    :action="
      Web.UpdateWebChannelVisitorInterfaceAction.url({
        channel: props.channel.id,
      })
    "
    method="put"
    class="space-y-6"
  >
    <template #default="{ errors, processing }">
      <input
        type="hidden"
        name="site_name"
        :value="draft.headerEnabled ? draft.siteName : ''"
      />
      <input
        type="hidden"
        name="subtitle"
        :value="draft.headerEnabled ? draft.subtitle : ''"
      />
      <input
        type="hidden"
        name="header_enabled"
        :value="draft.headerEnabled ? '1' : '0'"
      />
      <input
        v-if="!draft.headerEnabled"
        type="hidden"
        name="icon_id"
        value=""
      />
      <input
        type="hidden"
        name="visitor_identity_mode"
        :value="draft.visitorIdentityMode"
      />
      <template v-if="draft.visitorIdentityMode !== 'unified_service'">
        <input type="hidden" name="service_display_name" value="" />
        <input type="hidden" name="service_avatar_id" value="" />
      </template>
      <input
        type="hidden"
        name="greeting_message"
        :value="draft.greetingMessage"
      />
      <input
        type="hidden"
        name="composer_placeholder"
        :value="draft.composerPlaceholder"
      />
      <input type="hidden" name="theme_color" :value="draft.themeColor" />
      <input
        type="hidden"
        name="home_mode_enabled"
        :value="draft.homeModeEnabled ? '1' : '0'"
      />
      <input
        type="hidden"
        name="home_welcome_message"
        :value="draft.homeModeEnabled ? draft.homeWelcomeMessage : ''"
      />
      <input
        type="hidden"
        name="suggestions_enabled"
        :value="draft.suggestionsEnabled ? '1' : '0'"
      />
      <template
        v-for="(item, index) in normalizedSuggestionItems"
        :key="`${item}-${index}`"
      >
        <input
          type="hidden"
          :name="`suggestion_items[${index}]`"
          :value="item"
        />
      </template>

      <div class="max-w-2xl space-y-8">
        <section class="space-y-5">
          <div class="flex items-center justify-between gap-4">
            <Label>{{ t('展示标题栏') }}</Label>
            <Switch v-model="draft.headerEnabled" />
          </div>

          <template v-if="draft.headerEnabled">
            <div class="grid gap-2">
              <Label for="visitor_site_name" required>
                {{ t('页面标题') }}
              </Label>
              <Input id="visitor_site_name" v-model="draft.siteName" required />
              <InputError :message="errors.site_name" />
            </div>

            <div class="grid gap-2">
              <Label for="visitor_subtitle">{{ t('页面副标题') }}</Label>
              <Input id="visitor_subtitle" v-model="draft.subtitle" />
              <InputError :message="errors.subtitle" />
            </div>

            <ImageUploadField
              :label="t('页面图标')"
              name="icon_id"
              purpose="channel_icon"
              :initial-preview="props.channel.visitor_interface.icon_url ?? ''"
              :initial-value="props.channel.visitor_interface.icon_id ?? ''"
              variant="logo"
              :error="errors.icon_id"
              input-id="visitor_page_icon"
            />
          </template>
        </section>

        <section class="space-y-5">
          <div class="grid gap-2">
            <Label>{{ t('客服身份展示') }}</Label>
            <Select v-model="draft.visitorIdentityMode" :disabled="processing">
              <SelectTrigger class="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in props.formOptions
                    .visitor_identity_mode_options"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <InputError :message="errors.visitor_identity_mode" />
          </div>

          <template v-if="draft.visitorIdentityMode === 'unified_service'">
            <ImageUploadField
              :label="t('客服头像')"
              name="service_avatar_id"
              purpose="avatar"
              :initial-preview="
                props.channel.visitor_interface.service_avatar_url ?? ''
              "
              :initial-value="
                props.channel.visitor_interface.service_avatar_id ?? ''
              "
              variant="avatar"
              :error="errors.service_avatar_id"
              input-id="visitor_service_avatar"
            />

            <div class="grid gap-2">
              <Label for="visitor_service_display_name">
                {{ t('客服昵称') }}
              </Label>
              <Input
                id="visitor_service_display_name"
                v-model="draft.serviceDisplayName"
                name="service_display_name"
              />
              <InputError :message="errors.service_display_name" />
            </div>
          </template>

          <div class="grid gap-2">
            <Label for="visitor_greeting_message">{{ t('欢迎语') }}</Label>
            <Textarea
              id="visitor_greeting_message"
              v-model="draft.greetingMessage"
              rows="3"
            />
            <InputError :message="errors.greeting_message" />
          </div>

          <div class="grid gap-2">
            <Label for="visitor_composer_placeholder">
              {{ t('输入框提示内容') }}
            </Label>
            <Input
              id="visitor_composer_placeholder"
              v-model="draft.composerPlaceholder"
            />
            <InputError :message="errors.composer_placeholder" />
          </div>
        </section>

        <section class="space-y-5">
          <div class="grid gap-2">
            <Label>{{ t('主题颜色') }}</Label>
            <div class="flex flex-wrap gap-2.5">
              <button
                v-for="color in themeColorOptions"
                :key="color"
                type="button"
                :aria-label="color"
                :title="color"
                class="flex size-8 items-center justify-center rounded-full ring-offset-2 transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                :class="
                  draft.themeColor === color
                    ? 'ring-2 ring-foreground'
                    : 'ring-1 ring-border'
                "
                :style="{ backgroundColor: color }"
                @click="draft.themeColor = color"
              >
                <Check
                  v-if="draft.themeColor === color"
                  class="size-4 text-white"
                  :stroke-width="3"
                />
              </button>
            </div>
            <InputError :message="errors.theme_color" />
          </div>

          <div class="flex items-center justify-between gap-4">
            <div class="space-y-1">
              <Label>{{ t('首页模式') }}</Label>
              <p class="text-sm text-muted-foreground">
                {{ t('开启后访客先看到欢迎屏，再进入聊天') }}
              </p>
            </div>
            <Switch v-model="draft.homeModeEnabled" />
          </div>

          <div v-if="draft.homeModeEnabled" class="grid gap-2">
            <Label for="visitor_home_welcome_message" required>
              {{ t('首页欢迎语') }}
            </Label>
            <Textarea
              id="visitor_home_welcome_message"
              v-model="draft.homeWelcomeMessage"
              rows="2"
              required
              :maxlength="HOME_WELCOME_MAX_LENGTH"
            />
            <InputError :message="errors.home_welcome_message" />
          </div>
        </section>

        <section class="space-y-5">
          <div class="flex items-center justify-between gap-4">
            <Label>{{ t('展示猜你想问') }}</Label>
            <Switch v-model="draft.suggestionsEnabled" />
          </div>

          <div v-if="draft.suggestionsEnabled" class="space-y-3">
            <div class="flex items-center justify-between gap-3">
              <Label>{{ t('问题列表') }}</Label>
              <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="suggestionItems.length >= MAX_SUGGESTION_ITEMS"
                @click="addSuggestion"
              >
                <Plus class="mr-2 size-4" />
                {{ t('添加问题') }}
              </Button>
            </div>

            <div class="space-y-2">
              <div
                v-for="(_, index) in suggestionItems"
                :key="index"
                class="flex items-center gap-2"
              >
                <Input v-model="suggestionItems[index]" />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  :title="t('删除')"
                  @click="removeSuggestion(index)"
                >
                  <Trash2 class="size-4" />
                </Button>
              </div>
            </div>

            <p class="text-sm text-muted-foreground">
              {{ t('最多展示 6 个问题，空白项不会保存。') }}
            </p>
            <InputError :message="errors.suggestion_items" />
          </div>
        </section>

        <FormActions :submit-label="t('保存')" :processing="processing" />
      </div>
    </template>
  </Form>
</template>
