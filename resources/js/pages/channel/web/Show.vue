<!--
  文件说明：网站渠道页面，承接渠道列表、详情和嵌入配置管理。
-->
<script setup lang="ts">
import ChannelLivePreview from '@/components/channel/ChannelLivePreview.vue';
import {
  createChannelPreviewDraft,
  provideChannelPreviewDraft,
} from '@/composables/useChannelPreviewDraft';
import { useI18n } from '@/composables/useI18n';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import ChannelsLayout from '@/layouts/ChannelsLayout.vue';
import AccessTab from '@/pages/channel/web/tabs/AccessTab.vue';
import BasicTab from '@/pages/channel/web/tabs/BasicTab.vue';
import EntryDeviceTab from '@/pages/channel/web/tabs/EntryDeviceTab.vue';
import ParamMappingTab from '@/pages/channel/web/tabs/ParamMappingTab.vue';
import VisitorInterfaceTab from '@/pages/channel/web/tabs/VisitorInterfaceTab.vue';
import type { ShowWebChannelDetailPagePropsData } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
const props = defineProps<ShowWebChannelDetailPagePropsData>();
const { t } = useI18n();

// 贯穿各 tab 的实时预览草稿：各配置 tab 直接读写它，右侧常驻预览据此实时合成访客端外观。
const previewDraft = createChannelPreviewDraft(props.web_channel);
provideChannelPreviewDraft(previewDraft);

type TabKey =
  | 'basic'
  | 'visitor-interface'
  | 'access'
  | 'entry-device'
  | 'params';

const TAB_VALUES = [
  'basic',
  'visitor-interface',
  'access',
  'entry-device',
  'params',
] as const satisfies readonly TabKey[];

const activeTab = useUrlTab<TabKey>('tab', {
  defaultValue: 'basic',
  valid: TAB_VALUES,
});

const tabs = computed<{ value: TabKey; label: string }[]>(() => [
  { value: 'basic', label: t('基本信息') },
  { value: 'visitor-interface', label: t('访客界面') },
  { value: 'access', label: t('接入方式') },
  { value: 'entry-device', label: t('入口与设备') },
  { value: 'params', label: t('自定义传参') },
]);
</script>

<template>
  <AppLayout>
    <Head :title="props.web_channel.name" />

    <ChannelsLayout content-class="max-w-none">
      <div class="space-y-6">
        <div class="border-b border-border">
          <nav class="-mb-px flex flex-wrap gap-6">
            <button
              v-for="tab in tabs"
              :key="tab.value"
              type="button"
              class="relative -mb-px border-b-2 px-1 pb-3 text-base font-semibold transition-colors"
              :class="
                activeTab === tab.value
                  ? 'border-primary text-foreground'
                  : 'border-transparent text-muted-foreground hover:text-foreground'
              "
              @click="activeTab = tab.value"
            >
              {{ tab.label }}
            </button>
          </nav>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_27rem]">
          <div class="min-w-0">
            <BasicTab
              v-if="activeTab === 'basic'"
              :channel="props.web_channel"
              :form-options="props.form_options"
            />
            <VisitorInterfaceTab
              v-else-if="activeTab === 'visitor-interface'"
              :channel="props.web_channel"
              :form-options="props.form_options"
            />
            <AccessTab
              v-else-if="activeTab === 'access'"
              :channel="props.web_channel"
            />
            <EntryDeviceTab
              v-else-if="activeTab === 'entry-device'"
              :channel="props.web_channel"
              :form-options="props.form_options"
            />
            <ParamMappingTab
              v-else-if="activeTab === 'params'"
              :channel="props.web_channel"
              :form-options="props.form_options"
            />
          </div>

          <aside class="xl:sticky xl:top-6 xl:self-start">
            <div class="space-y-3">
              <p class="text-sm font-medium">{{ t('实时预览') }}</p>
              <ChannelLivePreview />
            </div>
          </aside>
        </div>
      </div>
    </ChannelsLayout>
  </AppLayout>
</template>
