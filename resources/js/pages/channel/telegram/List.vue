<!--
  文件说明：Telegram 渠道列表页面，承接渠道列表与删除操作；
  消费后端 ShowTelegramChannelListPagePropsData。
-->
<script setup lang="ts">
import Telegram from '@/actions/App/Actions/Channel/Telegram';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import ChannelsLayout from '@/layouts/ChannelsLayout.vue';
import type { ShowTelegramChannelListPagePropsData } from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MoreHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<ShowTelegramChannelListPagePropsData>();
const { t } = useI18n();

const deleteForm = useForm({});
const deletingChannelId = ref<string | null>(null);

const buildChannelListPageUrl = (page: number): string => {
  return Telegram.ListTelegramChannelsAction.url({
    query: { page },
  });
};

const selectedChannel = computed(
  () =>
    props.channel_list.find(
      (channel) => channel.id === deletingChannelId.value,
    ) ?? null,
);

const confirmDelete = () => {
  if (!selectedChannel.value || deleteForm.processing) {
    return;
  }

  deleteForm.delete(
    Telegram.DeleteTelegramChannelAction.url({
      channel: selectedChannel.value.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deletingChannelId.value = null;
      },
    },
  );
};

const handleDeleteDialogOpenChange = (open: boolean) => {
  if (!open) {
    deletingChannelId.value = null;
  }
};
</script>

<template>
  <AppLayout>
    <Head :title="t('Telegram 渠道')" />

    <ChannelsLayout content-class="max-w-none">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('Telegram 渠道')"
            :description="
              t('接入 Telegram Bot：访客在 Telegram 上即可与 AI 客服对话。')
            "
          />

          <div class="flex items-center gap-2">
            <Button as-child>
              <Link :href="Telegram.ShowCreateTelegramChannelPageAction.url()">
                {{ t('创建渠道') }}
              </Link>
            </Button>
            <Button variant="outline" as-child>
              <Link :href="Telegram.ListTelegramChannelTrashAction.url()">
                {{ t('回收站') }}
              </Link>
            </Button>
          </div>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('渠道名称') }}</th>
                  <th class="px-4 py-3">{{ t('接待方案') }}</th>
                  <th class="px-4 py-3">{{ t('连接状态') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="channel in props.channel_list"
                  :key="channel.id"
                  class="border-t bg-background align-middle"
                >
                  <td class="px-4 py-3">
                    <span class="font-medium">{{ channel.name }}</span>
                    <span
                      v-if="channel.bot_username"
                      class="ml-2 text-xs text-muted-foreground"
                    >
                      @{{ channel.bot_username }}
                    </span>
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    <span v-if="channel.reception_plan_name">
                      {{ channel.reception_plan_name }}
                    </span>
                    <span v-else>{{ t('未部署接待方案') }}</span>
                  </td>

                  <td class="px-4 py-3">
                    <Badge
                      :variant="
                        channel.webhook_active ? 'default' : 'secondary'
                      "
                    >
                      {{
                        channel.webhook_active ? t('正在接收消息') : t('未注册')
                      }}
                    </Badge>
                  </td>

                  <td class="px-4 py-3">
                    <div class="flex justify-end gap-2">
                      <Button size="sm" variant="outline" as-child>
                        <Link
                          :href="
                            Telegram.ShowTelegramChannelDetailPageAction.url({
                              channel: channel.id,
                            })
                          "
                        >
                          {{ t('配置') }}
                        </Link>
                      </Button>

                      <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                          <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :aria-label="t('更多操作')"
                          >
                            <MoreHorizontal class="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-36">
                          <DropdownMenuItem
                            class="text-destructive focus:text-destructive"
                            @select="deletingChannelId = channel.id"
                          >
                            {{ t('删除') }}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>

                <tr v-if="props.channel_list.length === 0">
                  <td
                    colspan="4"
                    class="px-4 py-8 text-center text-muted-foreground"
                  >
                    {{ t('暂无 Telegram 渠道') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <ConfirmDeleteDialog
          :open="deletingChannelId !== null"
          :title="t('确认删除渠道？')"
          :detail-title="selectedChannel?.name"
          :detail-description="
            t(
              '删除后将从 Telegram 撤销 webhook，访客将无法再通过该 Bot 联系客服。',
            )
          "
          :processing="deleteForm.processing"
          @update:open="handleDeleteDialogOpenChange"
          @confirm="confirmDelete"
        />

        <div
          v-if="props.channel_list_pagination.last_page > 1"
          class="rounded-lg border p-4"
        >
          <PaginationNavigator
            :pagination="props.channel_list_pagination"
            :page-url="buildChannelListPageUrl"
          />
        </div>
      </div>
    </ChannelsLayout>
  </AppLayout>
</template>
