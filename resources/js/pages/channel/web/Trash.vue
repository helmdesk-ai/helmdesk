<!--
  文件说明：网站渠道回收站页面，承接已删除渠道查看和恢复。
-->
<script setup lang="ts">
import Web from '@/actions/App/Actions/Channel/Web';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import RestoreConfirmDialog from '@/components/common/RestoreConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import ChannelsLayout from '@/layouts/ChannelsLayout.vue';
import type { ShowWebChannelTrashPagePropsData } from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<ShowWebChannelTrashPagePropsData>();
const { t } = useI18n();
const { formatDateTime } = useDateTime();
const currentWorkspace = useRequiredWorkspace();
const restoreForm = useForm({});

const buildTrashPageUrl = (page: number): string => {
  return Web.ListWebChannelTrashAction.url(currentWorkspace.value.slug, {
    query: { page },
  });
};
</script>

<template>
  <AppLayout>
    <Head :title="t('渠道回收站')" />

    <ChannelsLayout content-class="max-w-none">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('渠道回收站')"
            :description="t('查看已删除的渠道并可恢复')"
          />

          <Button variant="outline" class="shrink-0" as-child>
            <Link :href="Web.ListWebChannelsAction.url(currentWorkspace.slug)">
              {{ t('返回列表') }}
            </Link>
          </Button>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('渠道名称') }}</th>
                  <th class="px-4 py-3">{{ t('接待方案版本') }}</th>
                  <th class="px-4 py-3">{{ t('删除时间') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="channel in props.trashed_channel_list"
                  :key="channel.id"
                  class="border-b last:border-b-0"
                >
                  <td class="px-4 py-3">
                    <div class="font-medium">{{ channel.name }}</div>
                    <div class="text-xs text-muted-foreground">
                      {{ channel.code }}
                    </div>
                  </td>
                  <td class="px-4 py-3 text-muted-foreground">
                    <span v-if="channel.reception_plan_name">
                      {{ channel.reception_plan_name }}
                    </span>
                    <span v-else>{{ t('未绑定接待方案') }}</span>
                  </td>
                  <td class="px-4 py-3 text-muted-foreground">
                    {{
                      channel.deleted_at
                        ? formatDateTime(channel.deleted_at)
                        : '-'
                    }}
                  </td>
                  <td class="px-4 py-3 text-right">
                    <RestoreConfirmDialog
                      :title="t('确认恢复渠道？')"
                      :description="t('恢复后将重新出现在网站渠道列表中。')"
                      :processing="restoreForm.processing"
                      :submitting="restoreForm.processing"
                      @confirm="
                        restoreForm.put(
                          Web.RestoreWebChannelAction.url({
                            slug: currentWorkspace.slug,
                            channel: channel.id,
                          }),
                          { preserveScroll: true },
                        )
                      "
                    >
                      <div class="font-medium">{{ channel.name }}</div>
                    </RestoreConfirmDialog>
                  </td>
                </tr>

                <tr v-if="props.trashed_channel_list.length === 0">
                  <td
                    colspan="4"
                    class="px-4 py-8 text-center text-muted-foreground"
                  >
                    {{ t('暂无已删除的渠道') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div
            v-if="props.trashed_channel_list_pagination.last_page > 1"
            class="border-t p-4"
          >
            <PaginationNavigator
              :pagination="props.trashed_channel_list_pagination"
              :page-url="buildTrashPageUrl"
            />
          </div>
        </div>
      </div>
    </ChannelsLayout>
  </AppLayout>
</template>
