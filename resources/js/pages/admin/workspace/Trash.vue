<!--
  文件说明：系统工作区管理页面，承接工作区列表、创建、编辑、详情和回收站。
-->
<script setup lang="ts">
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import RestoreConfirmDialog from '@/components/common/RestoreConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import admin from '@/routes/admin';
import type { ShowWorkspaceTrashPagePropsData } from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
const { t } = useI18n();
const { formatDateTime } = useDateTime();
const props = defineProps<ShowWorkspaceTrashPagePropsData>();
const restoreForm = useForm({});

const buildWorkspaceTrashPageUrl = (page: number): string => {
  return admin.workspaces.trash.url({ query: { page } });
};
</script>

<template>
  <SystemAppLayout>
    <Head :title="t('工作区回收站')" />
    <div class="px-4 py-6 sm:px-6">
      <div class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <div class="flex items-start justify-between gap-4">
            <HeadingSmall
              :title="t('工作区回收站')"
              :description="t('查看已删除的工作区并可恢复')"
            />

            <Button variant="outline" class="shrink-0" as-child>
              <Link :href="admin.workspaces.index.url()">
                {{ t('返回列表') }}
              </Link>
            </Button>
          </div>

          <div class="rounded-lg border">
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="border-b bg-muted/30 text-muted-foreground">
                  <tr>
                    <th class="px-4 py-3 text-left font-medium">
                      {{ t('名称') }}
                    </th>
                    <th class="px-4 py-3 text-left font-medium">
                      {{ t('所有者') }}
                    </th>
                    <th class="px-4 py-3 text-left font-medium">
                      {{ t('创建时间') }}
                    </th>
                    <th class="px-4 py-3 text-left font-medium">
                      {{ t('删除时间') }}
                    </th>
                    <th class="px-4 py-3 text-left font-medium">
                      {{ t('成员数') }}
                    </th>
                    <th class="px-4 py-3 text-right font-medium">
                      {{ t('操作') }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="ws in props.workspace_trash_list"
                    :key="ws.id"
                    class="border-b last:border-b-0"
                  >
                    <td class="px-4 py-3">
                      <div class="font-medium">{{ ws.name }}</div>
                      <div class="text-xs text-muted-foreground">
                        {{ ws.slug || '-' }}
                      </div>
                    </td>
                    <td class="px-4 py-3">
                      <div class="font-medium">{{ ws.owner?.name || '-' }}</div>
                      <div class="text-xs text-muted-foreground">
                        {{ ws.owner?.email || '' }}
                      </div>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">
                      {{ formatDateTime(ws.created_at) }}
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">
                      {{ ws.deleted_at ? formatDateTime(ws.deleted_at) : '-' }}
                    </td>
                    <td class="px-4 py-3">
                      {{ ws.members_count }}
                    </td>
                    <td class="px-4 py-3 text-right">
                      <RestoreConfirmDialog
                        :title="t('确认恢复工作区？')"
                        :description="t('恢复后将重新出现在工作区管理列表中。')"
                        :processing="restoreForm.processing"
                        :submitting="restoreForm.processing"
                        @confirm="
                          restoreForm.put(admin.workspaces.restore.url(ws.id), {
                            preserveScroll: true,
                          })
                        "
                      >
                        <div class="font-medium">{{ ws.name }}</div>
                        <div class="text-muted-foreground">
                          {{ ws.owner?.name || '-' }}
                        </div>
                      </RestoreConfirmDialog>
                    </td>
                  </tr>

                  <tr v-if="props.workspace_trash_list.length === 0">
                    <td
                      colspan="6"
                      class="px-4 py-8 text-center text-muted-foreground"
                    >
                      {{ t('暂无已删除的工作区') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div
              v-if="props.workspace_trash_list_pagination.last_page > 1"
              class="border-t p-4"
            >
              <PaginationNavigator
                :pagination="props.workspace_trash_list_pagination"
                :page-url="buildWorkspaceTrashPageUrl"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  </SystemAppLayout>
</template>
