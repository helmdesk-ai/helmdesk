<!--
  文件说明：Telegram 渠道详情页面，以标签页组织基本信息与接入设置（Webhook / Bot Token）；
  消费后端 ShowTelegramChannelDetailPagePropsData。
-->
<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import ChannelsLayout from '@/layouts/ChannelsLayout.vue';
import BasicTab from '@/pages/channel/telegram/tabs/BasicTab.vue';
import ConnectionTab from '@/pages/channel/telegram/tabs/ConnectionTab.vue';
import type { ShowTelegramChannelDetailPagePropsData } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<ShowTelegramChannelDetailPagePropsData>();
const { t } = useI18n();

type TabKey = 'basic' | 'connection';

const TAB_VALUES = ['basic', 'connection'] as const satisfies readonly TabKey[];

const activeTab = useUrlTab<TabKey>('tab', {
  defaultValue: 'basic',
  valid: TAB_VALUES,
});

const tabs = computed<{ value: TabKey; label: string }[]>(() => [
  { value: 'basic', label: t('基本信息') },
  { value: 'connection', label: t('接入设置') },
]);
</script>

<template>
  <AppLayout>
    <Head :title="props.telegram_channel.name" />

    <ChannelsLayout>
      <div class="max-w-2xl space-y-6">
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

        <BasicTab
          v-if="activeTab === 'basic'"
          :channel="props.telegram_channel"
          :form-options="props.form_options"
        />
        <ConnectionTab
          v-else-if="activeTab === 'connection'"
          :channel="props.telegram_channel"
        />
      </div>
    </ChannelsLayout>
  </AppLayout>
</template>
