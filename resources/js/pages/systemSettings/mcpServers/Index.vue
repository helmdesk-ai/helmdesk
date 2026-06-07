<!--
  文件说明：系统 MCP 服务列表页面，承接服务列表、工具明细浮层、连接测试和全量工具同步。
  消费后端 ShowSystemMcpServersPagePropsData。
-->
<script setup lang="ts">
import Mcp from '@/actions/App/Actions/Mcp';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type {
  McpServerData,
  McpToolData,
  ShowSystemMcpServersPagePropsData,
} from '@/types/generated';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { LoaderCircle, MoreHorizontal } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<ShowSystemMcpServersPagePropsData>();

const { t } = useI18n();
const { toast } = useToast();

const deleteForm = useForm({});
const deletingServerSlug = ref<string | null>(null);
const checkingServerSlug = ref<string | null>(null);
const isQueueingSync = ref(false);
const pollingTimer = ref<number | null>(null);

const deletingServer = computed(
  () =>
    props.servers.find((server) => server.slug === deletingServerSlug.value) ??
    null,
);

const hasSyncingServer = computed(() =>
  props.servers.some((server) => server.last_sync_status === 'syncing'),
);

const isSyncButtonDisabled = computed(
  () =>
    isQueueingSync.value ||
    hasSyncingServer.value ||
    props.servers.length === 0,
);

function toolDescription(tool: McpToolData): string {
  return tool.description ?? t('远端未提供描述');
}

function openDeleteDialog(server: McpServerData): void {
  deletingServerSlug.value = server.slug;
}

function handleDeleteDialogOpenChange(open: boolean): void {
  if (!open) {
    deletingServerSlug.value = null;
  }
}

function confirmDelete(): void {
  if (!deletingServer.value || deleteForm.processing) {
    return;
  }

  deleteForm.delete(
    Mcp.DeleteMcpServerAction.url({
      server: deletingServer.value.slug,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deletingServerSlug.value = null;
      },
    },
  );
}

async function checkConnection(server: McpServerData): Promise<void> {
  checkingServerSlug.value = server.slug;

  try {
    const { data } = await axios.post(
      Mcp.CheckMcpServerAction['/admin/manage/mcp-servers/{server}/check'].url({
        server: server.slug,
      }),
    );

    const message =
      typeof data?.message === 'string' && data.message.length > 0
        ? data.message
        : '';

    if (data?.success) {
      toast.success(message || t('连接测试成功'));
    } else {
      toast.error(message || t('连接测试失败'));
    }
  } catch {
    // 失败响应由全局 axios interceptor 统一处理。
  } finally {
    checkingServerSlug.value = null;
  }
}

function reloadServers(onFinish?: () => void): void {
  router.reload({
    only: ['servers'],
    preserveScroll: true,
    onFinish,
  });
}

function clearPollingTimer(): void {
  if (pollingTimer.value !== null) {
    window.clearTimeout(pollingTimer.value);
    pollingTimer.value = null;
  }
}

function scheduleSyncPolling(): void {
  clearPollingTimer();

  if (!hasSyncingServer.value) {
    return;
  }

  pollingTimer.value = window.setTimeout(() => {
    reloadServers(() => window.setTimeout(scheduleSyncPolling, 0));
  }, 2000);
}

async function syncAllTools(): Promise<void> {
  if (isSyncButtonDisabled.value) {
    return;
  }

  isQueueingSync.value = true;

  try {
    await axios.post(Mcp.SyncAllMcpServerToolsAction.url());
    reloadServers(() => window.setTimeout(scheduleSyncPolling, 0));
  } catch {
    // 失败响应由全局 axios interceptor 统一处理。
  } finally {
    isQueueingSync.value = false;
  }
}

// 进入页面或同步状态变化时驱动轮询：仍有服务在同步则继续刷新列表。
watch(
  () => props.servers.map((server) => server.last_sync_status).join('|'),
  scheduleSyncPolling,
  { immediate: true },
);

onBeforeUnmount(clearPollingTimer);
</script>

<template>
  <AppLayout>
    <Head :title="t('MCP 服务')" />

    <SystemSettingsLayout content-class="max-w-none">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('MCP 服务')"
            :description="t('用 MCP 协议接入外部能力，供不同业务场景调用')"
          />

          <div class="flex items-center gap-2">
            <Button as-child>
              <Link :href="Mcp.ShowCreateMcpServerPageAction.url()">
                {{ t('新增 MCP 服务') }}
              </Link>
            </Button>
            <Button
              variant="outline"
              :disabled="isSyncButtonDisabled"
              @click="syncAllTools"
            >
              {{ isQueueingSync || hasSyncingServer ? t('同步中') : t('同步') }}
            </Button>
          </div>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('名称') }}</th>
                  <th class="px-4 py-3">{{ t('端点地址') }}</th>
                  <th class="px-4 py-3">{{ t('认证方式') }}</th>
                  <th class="px-4 py-3">{{ t('工具数') }}</th>
                  <th class="w-56 px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="server in props.servers" :key="server.id">
                  <tr class="border-t bg-background align-middle">
                    <td class="px-4 py-3">
                      <span class="font-medium">{{ server.name }}</span>
                    </td>

                    <td class="max-w-md px-4 py-3">
                      <span class="block truncate text-muted-foreground">
                        {{ server.endpoint_url }}
                      </span>
                    </td>

                    <td class="px-4 py-3 text-muted-foreground">
                      {{ server.auth_method_label }}
                    </td>

                    <td class="px-4 py-3">
                      <div class="flex items-center gap-2">
                        <Popover>
                          <PopoverTrigger as-child>
                            <button
                              type="button"
                              class="inline-flex items-center text-left"
                            >
                              <span
                                class="font-medium underline-offset-4 hover:underline"
                              >
                                {{ server.tools_count }}
                              </span>
                            </button>
                          </PopoverTrigger>
                          <PopoverContent
                            align="start"
                            side="bottom"
                            class="w-96 max-w-[calc(100vw-2rem)] p-0"
                          >
                            <div
                              v-if="server.tools.length > 0"
                              class="max-h-80 divide-y overflow-y-auto"
                            >
                              <div
                                v-for="tool in server.tools"
                                :key="tool.id"
                                class="px-4 py-3"
                              >
                                <div class="flex items-center gap-2">
                                  <span class="font-mono text-sm font-medium">
                                    {{ tool.name }}
                                  </span>
                                  <Badge
                                    v-if="tool.removed_at"
                                    variant="secondary"
                                    class="text-[10px]"
                                  >
                                    {{ t('已下线') }}
                                  </Badge>
                                </div>
                                <p class="mt-1 text-sm text-muted-foreground">
                                  {{ toolDescription(tool) }}
                                </p>
                              </div>
                            </div>

                            <div
                              v-else
                              class="px-4 py-6 text-sm text-muted-foreground"
                            >
                              {{ t('该 MCP 服务暂无工具') }}
                            </div>
                          </PopoverContent>
                        </Popover>

                        <LoaderCircle
                          v-if="server.last_sync_status === 'syncing'"
                          class="h-3.5 w-3.5 animate-spin text-muted-foreground"
                        />
                        <TooltipProvider
                          v-else-if="server.last_sync_status === 'failed'"
                        >
                          <Tooltip>
                            <TooltipTrigger as-child>
                              <span
                                class="cursor-default text-xs font-medium text-destructive"
                              >
                                {{ server.last_sync_status_label }}
                              </span>
                            </TooltipTrigger>
                            <TooltipContent
                              v-if="server.last_sync_error"
                              class="max-w-xs break-words"
                            >
                              {{ server.last_sync_error }}
                            </TooltipContent>
                          </Tooltip>
                        </TooltipProvider>
                        <span v-else class="text-xs text-muted-foreground">
                          {{ server.last_sync_status_label }}
                        </span>
                      </div>
                    </td>

                    <td class="w-56 px-4 py-3">
                      <div class="flex justify-end gap-2 whitespace-nowrap">
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          :disabled="checkingServerSlug === server.slug"
                          @click="checkConnection(server)"
                        >
                          <LoaderCircle
                            v-if="checkingServerSlug === server.slug"
                            class="mr-2 h-4 w-4 animate-spin"
                          />
                          {{ t('测试') }}
                        </Button>

                        <Button size="sm" variant="outline" as-child>
                          <Link
                            :href="
                              Mcp.ShowEditMcpServerPageAction.url({
                                server: server.slug,
                              })
                            "
                          >
                            {{ t('编辑') }}
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
                              @select="openDeleteDialog(server)"
                            >
                              {{ t('删除') }}
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </td>
                  </tr>
                </template>

                <tr v-if="props.servers.length === 0">
                  <td
                    colspan="5"
                    class="px-4 py-8 text-center text-muted-foreground"
                  >
                    {{ t('暂无 MCP 服务') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <ConfirmDeleteDialog
          :open="deletingServerSlug !== null"
          :title="
            t('删除 MCP 服务 “{name}”？', {
              name: deletingServer?.name ?? '',
            })
          "
          :detail-description="
            t('删除后将同时移除已缓存的 {count} 个工具记录。', {
              count: deletingServer?.tools_count ?? 0,
            })
          "
          :processing="deleteForm.processing"
          @update:open="handleDeleteDialogOpenChange"
          @confirm="confirmDelete"
        />
      </div>
    </SystemSettingsLayout>
  </AppLayout>
</template>
