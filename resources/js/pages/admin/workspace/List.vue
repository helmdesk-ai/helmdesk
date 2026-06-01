<!--
  文件说明：系统工作区管理页面，承接工作区列表、创建、编辑、详情和回收站。
-->
<script setup lang="ts">
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import admin from '@/routes/admin';
import type { ShowWorkspaceListPagePropsData } from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MoreHorizontal } from 'lucide-vue-next';
import { ref } from 'vue';
const { t } = useI18n();
const { formatDateTime } = useDateTime();
const deleteForm = useForm({});
const props = defineProps<ShowWorkspaceListPagePropsData>();
const deletingWorkspace = ref<{
  id: string;
  name: string;
  ownerName: string;
} | null>(null);

const buildWorkspaceListPageUrl = (page: number): string => {
  return admin.workspaces.index.url({ query: { page } });
};

const openDeleteDialog = (workspace: {
  id: string;
  name: string;
  ownerName: string;
}) => {
  deletingWorkspace.value = workspace;
};

const closeDeleteDialog = (open: boolean) => {
  if (open || deleteForm.processing) {
    return;
  }

  deletingWorkspace.value = null;
};

const submitDelete = () => {
  if (!deletingWorkspace.value) {
    return;
  }

  deleteForm.delete(admin.workspaces.destroy.url(deletingWorkspace.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      deletingWorkspace.value = null;
    },
  });
};
</script>
<template>
  <SystemAppLayout>
    <Head :title="t('工作区管理')" />
    <div class="px-4 py-6 sm:px-6">
      <div class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <div class="flex items-start justify-between gap-4">
            <HeadingSmall
              :title="t('工作区管理')"
              :description="t('查看系统中所有工作区及其成员信息')"
            />
            <div class="flex items-center gap-2">
              <Button as-child>
                <Link :href="admin.workspaces.create.url()">
                  {{ t('创建工作区') }}
                </Link>
              </Button>
              <Button variant="outline" as-child>
                <Link :href="admin.workspaces.trash.url()">{{
                  t('回收站')
                }}</Link>
              </Button>
            </div>
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
                      {{ t('成员数') }}
                    </th>
                    <th class="px-4 py-3 text-right font-medium">
                      {{ t('操作') }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="ws in props.workspace_list"
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
                      <div class="font-medium">
                        {{ ws.owner?.name || '-' }}
                      </div>
                      <div class="text-xs text-muted-foreground">
                        {{ ws.owner?.email || '' }}
                      </div>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">
                      {{ formatDateTime(ws.created_at) }}
                    </td>
                    <td class="px-4 py-3">
                      {{ ws.members_count }}
                    </td>
                    <td class="px-4 py-3 text-right">
                      <div class="inline-flex items-center justify-end gap-2">
                        <Button variant="outline" size="sm" as-child>
                          <Link :href="admin.workspaces.edit.url(ws.id)">
                            {{ t('编辑') }}
                          </Link>
                        </Button>

                        <Button variant="outline" size="sm" as-child>
                          <Link :href="admin.workspaces.show.url(ws.id)">
                            {{ t('客服列表') }}
                          </Link>
                        </Button>

                        <Button
                          v-if="ws.owner?.id"
                          variant="outline"
                          size="sm"
                          as-child
                        >
                          <a
                            :href="admin.workspaces.loginAsOwner.url(ws.id)"
                            target="_blank"
                            rel="noopener noreferrer"
                          >
                            {{ t('进入工作区') }}
                          </a>
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
                              :disabled="deleteForm.processing"
                              @select="
                                openDeleteDialog({
                                  id: ws.id,
                                  name: ws.name,
                                  ownerName: ws.owner?.name || '-',
                                })
                              "
                            >
                              {{ t('删除') }}
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </td>
                  </tr>

                  <tr v-if="props.workspace_list.length === 0">
                    <td
                      colspan="5"
                      class="px-4 py-8 text-center text-muted-foreground"
                    >
                      {{ t('暂无工作区') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div
              v-if="props.workspace_list_pagination.last_page > 1"
              class="border-t p-4"
            >
              <PaginationNavigator
                :pagination="props.workspace_list_pagination"
                :page-url="buildWorkspaceListPageUrl"
              />
            </div>
          </div>
        </div>
      </div>
    </div>

    <ConfirmDeleteDialog
      :open="deletingWorkspace !== null"
      :title="t('确认删除工作区？')"
      :detail-title="deletingWorkspace?.name"
      :detail-description="t('将工作区放入回收站，可以后续恢复。')"
      :processing="deleteForm.processing"
      @update:open="closeDeleteDialog"
      @confirm="submitDelete"
    />
  </SystemAppLayout>
</template>
