<!--
  文件说明：网站渠道页面，承接渠道列表、详情和嵌入配置管理。
-->
<script setup lang="ts">
import Web from '@/actions/App/Actions/Channel/Web';
import EmbedHostCell from '@/components/channel/EmbedHostCell.vue';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import ChannelsLayout from '@/layouts/ChannelsLayout.vue';
import type { ShowWebChannelListPagePropsData } from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MoreHorizontal } from 'lucide-vue-next';
import { computed, ref } from 'vue';

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const props = defineProps<ShowWebChannelListPagePropsData>();
const { t } = useI18n();
const currentWorkspace = useRequiredWorkspace();

const deleteForm = useForm({});
const deletingChannelId = ref<string | null>(null);

const buildChannelListPageUrl = (page: number): string => {
  return Web.ListWebChannelsAction.url(currentWorkspace.value.slug, {
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
    Web.DeleteWebChannelAction.url({
      slug: currentWorkspace.value.slug,
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
    <Head :title="t('渠道')" />

    <ChannelsLayout content-class="max-w-none">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('网站渠道')"
            :description="
              t(
                '把 AI 放到你的官网：访客可通过网站嵌入代码或聊天链接和它聊天。',
              )
            "
          />

          <div class="flex items-center gap-2">
            <Button as-child>
              <Link
                :href="
                  Web.ShowCreateWebChannelPageAction.url(currentWorkspace.slug)
                "
              >
                {{ t('创建渠道') }}
              </Link>
            </Button>
            <Button variant="outline" as-child>
              <Link
                :href="Web.ListWebChannelTrashAction.url(currentWorkspace.slug)"
              >
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
                  <th class="px-4 py-3">{{ t('接待方案版本') }}</th>
                  <th class="px-4 py-3">{{ t('最近嵌入') }}</th>
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
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    <span v-if="channel.reception_plan_name">
                      {{ channel.reception_plan_name }}
                    </span>
                    <span v-else>{{ t('未绑定接待方案') }}</span>
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    <EmbedHostCell
                      :host="channel.last_embed_host"
                      :at="channel.last_embed_at"
                    />
                  </td>

                  <td class="px-4 py-3">
                    <div class="flex justify-end gap-2">
                      <Button size="sm" variant="outline" as-child>
                        <Link
                          :href="
                            Web.ShowWebChannelDetailPageAction.url({
                              slug: currentWorkspace.slug,
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
                    {{ t('暂无网站渠道') }}
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
              '删除后该渠道会被移到已删除列表，可随时恢复；对应的访客入口会暂时不可用。',
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
