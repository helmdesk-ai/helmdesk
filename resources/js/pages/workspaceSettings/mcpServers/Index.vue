<!--
  工作区 MCP 服务页面：左侧服务列表，右侧承接详情、创建和编辑表单。
-->
<script setup lang="ts">
import Mcp from '@/actions/App/Actions/Mcp';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import WorkspaceSettingsLayout from '@/layouts/WorkspaceSettingsLayout.vue';
import type {
  McpServerData,
  ShowWorkspaceMcpServersPagePropsData,
} from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { LoaderCircle, Plus, Server, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import McpServerFormPanel from './McpServerFormPanel.vue';
import McpToolListItem from './McpToolListItem.vue';

type RightPage = 'server_detail' | 'server_form';
type ServerFormMode = 'create' | 'edit';

const props = defineProps<ShowWorkspaceMcpServersPagePropsData>();

const { t } = useI18n();
const { toast } = useToast();
const { formatDateTime } = useDateTime();
const workspace = useRequiredWorkspace();

const selectedServerQueryParam = 'server';
const panelQueryParam = 'panel';

function serverExists(slug: string | null): slug is string {
  return slug !== null && props.servers.some((server) => server.slug === slug);
}

function defaultServerSlug(): string | null {
  return props.servers[0]?.slug ?? null;
}

function readSelectedServerFromUrl(): string | null {
  if (typeof window === 'undefined') {
    return defaultServerSlug();
  }

  const requested = new URLSearchParams(window.location.search).get(
    selectedServerQueryParam,
  );

  return serverExists(requested) ? requested : defaultServerSlug();
}

function readRightPanelFromUrl(selectedSlug: string | null): {
  page: RightPage;
  mode: ServerFormMode;
  editingSlug: string | null;
} {
  if (typeof window === 'undefined') {
    return { page: 'server_detail', mode: 'create', editingSlug: null };
  }

  const url = new URL(window.location.href);
  const panel = url.searchParams.get(panelQueryParam);

  if (panel === 'create') {
    return { page: 'server_form', mode: 'create', editingSlug: null };
  }

  if (panel === 'edit' && serverExists(selectedSlug)) {
    return { page: 'server_form', mode: 'edit', editingSlug: selectedSlug };
  }

  return { page: 'server_detail', mode: 'create', editingSlug: null };
}

function writeUrlState(
  slug: string | null,
  page: RightPage,
  mode: ServerFormMode,
  editingSlug: string | null,
): void {
  if (typeof window === 'undefined') {
    return;
  }

  const url = new URL(window.location.href);
  const serverSlug =
    page === 'server_form' && mode === 'edit' ? (editingSlug ?? slug) : slug;

  if (serverSlug === null || serverSlug === defaultServerSlug()) {
    url.searchParams.delete(selectedServerQueryParam);
  } else {
    url.searchParams.set(selectedServerQueryParam, serverSlug);
  }

  if (page === 'server_form') {
    url.searchParams.set(panelQueryParam, mode);
  } else {
    url.searchParams.delete(panelQueryParam);
  }

  window.history.replaceState(window.history.state, '', url.toString());
}

const selectedSlug = ref<string | null>(readSelectedServerFromUrl());
const initialRightPanel = readRightPanelFromUrl(selectedSlug.value);
const activeRightPage = ref<RightPage>(initialRightPanel.page);
const serverFormMode = ref<ServerFormMode>(initialRightPanel.mode);
const editingServerSlug = ref<string | null>(initialRightPanel.editingSlug);
const createBaselineSlugs = ref<string[] | null>(null);
const isCheckingSavedServer = ref(false);
const isSyncing = ref(false);
const deleteTarget = ref<McpServerData | null>(null);
const isDeleting = ref(false);

const selectedServer = computed<McpServerData | null>(
  () =>
    props.servers.find((server) => server.slug === selectedSlug.value) ?? null,
);

const editingServer = computed<McpServerData | null>(() => {
  if (editingServerSlug.value) {
    return (
      props.servers.find((server) => server.slug === editingServerSlug.value) ??
      null
    );
  }

  return selectedServer.value;
});

const formattedLastSync = computed(() => {
  if (!selectedServer.value?.last_synced_at) {
    return null;
  }

  return formatDateTime(selectedServer.value.last_synced_at);
});

watch(
  [selectedSlug, activeRightPage, serverFormMode, editingServerSlug],
  () => {
    writeUrlState(
      selectedSlug.value,
      activeRightPage.value,
      serverFormMode.value,
      editingServerSlug.value,
    );
  },
);

watch(
  () => props.servers,
  (servers) => {
    if (
      selectedSlug.value &&
      !servers.find((server) => server.slug === selectedSlug.value)
    ) {
      selectedSlug.value = servers[0]?.slug ?? null;
      activeRightPage.value = 'server_detail';
    } else if (!selectedSlug.value && servers.length > 0) {
      selectedSlug.value = servers[0].slug;
    }

    if (editingServerSlug.value) {
      const editingStillExists = servers.some(
        (server) => server.slug === editingServerSlug.value,
      );
      if (!editingStillExists) {
        editingServerSlug.value = null;
        activeRightPage.value = 'server_detail';
      }
    }

    selectCreatedServerIfAvailable();
    writeUrlState(
      selectedSlug.value,
      activeRightPage.value,
      serverFormMode.value,
      editingServerSlug.value,
    );
  },
);

onMounted(() => {
  writeUrlState(
    selectedSlug.value,
    activeRightPage.value,
    serverFormMode.value,
    editingServerSlug.value,
  );
});

function handleActionError(errors: Record<string, string | undefined>): void {
  const message = Object.values(errors).find(
    (value): value is string =>
      typeof value === 'string' && value.trim().length > 0,
  );

  if (message) {
    toast.warning(message);
  }
}

function selectServer(server: McpServerData): void {
  selectedSlug.value = server.slug;
  activeRightPage.value = 'server_detail';
  editingServerSlug.value = null;
}

function isServerRowActive(server: McpServerData): boolean {
  if (
    activeRightPage.value === 'server_form' &&
    serverFormMode.value === 'edit'
  ) {
    return editingServerSlug.value === server.slug;
  }

  return selectedSlug.value === server.slug;
}

function openCreateForm(): void {
  createBaselineSlugs.value = props.servers.map((server) => server.slug);
  serverFormMode.value = 'create';
  editingServerSlug.value = null;
  activeRightPage.value = 'server_form';
}

function openEditForm(server: McpServerData): void {
  selectedSlug.value = server.slug;
  createBaselineSlugs.value = null;
  serverFormMode.value = 'edit';
  editingServerSlug.value = server.slug;
  activeRightPage.value = 'server_form';
}

function closeServerForm(): void {
  activeRightPage.value = 'server_detail';
  editingServerSlug.value = null;
  createBaselineSlugs.value = null;
}

function selectCreatedServerIfAvailable(): boolean {
  if (!createBaselineSlugs.value) {
    return false;
  }

  const baseline = new Set(createBaselineSlugs.value);
  const created = props.servers.find((server) => !baseline.has(server.slug));

  if (!created) {
    return false;
  }

  selectedSlug.value = created.slug;
  createBaselineSlugs.value = null;

  return true;
}

function handleServerFormSaved(): void {
  if (serverFormMode.value === 'create') {
    selectCreatedServerIfAvailable();
  }

  activeRightPage.value = 'server_detail';
  editingServerSlug.value = null;
}

function toggleServer(server: McpServerData): void {
  router.put(
    Mcp.ToggleMcpServerAction.url({
      slug: workspace.value.slug,
      server: server.slug,
    }),
    {},
    {
      preserveScroll: true,
      onError: (errors) =>
        handleActionError(errors as Record<string, string | undefined>),
    },
  );
}

async function checkSavedServerConnection(): Promise<void> {
  if (!selectedServer.value) {
    return;
  }

  isCheckingSavedServer.value = true;

  try {
    const { data } = await axios.post(
      Mcp.CheckMcpServerAction[
        '/w/{slug}/manage/mcp-servers/{server}/check'
      ].url({
        slug: workspace.value.slug,
        server: selectedServer.value.slug,
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
    // 网络/5xx 等异常由全局 axios interceptor 统一 toast，这里不再重复。
  } finally {
    isCheckingSavedServer.value = false;
  }
}

async function syncTools(): Promise<void> {
  if (!selectedSlug.value) {
    return;
  }

  isSyncing.value = true;

  try {
    const { data } = await axios.post(
      Mcp.SyncMcpServerToolsAction.url({
        slug: workspace.value.slug,
        server: selectedSlug.value,
      }),
    );

    const message =
      typeof data?.message === 'string' && data.message.length > 0
        ? data.message
        : '';

    if (data?.success) {
      toast.success(message || t('同步成功'));
    } else {
      toast.error(message || t('同步失败'));
    }

    router.reload({
      only: ['servers'],
    });
  } catch {
    // 网络/5xx 等异常由全局 axios interceptor 统一 toast，这里不再重复。
  } finally {
    isSyncing.value = false;
  }
}

function openDeleteDialog(server: McpServerData): void {
  deleteTarget.value = server;
}

function closeDeleteDialog(open: boolean): void {
  if (open || isDeleting.value) {
    return;
  }

  deleteTarget.value = null;
}

function confirmDelete(): void {
  if (!deleteTarget.value || isDeleting.value) {
    return;
  }

  isDeleting.value = true;

  router.delete(
    Mcp.DeleteMcpServerAction.url({
      slug: workspace.value.slug,
      server: deleteTarget.value.slug,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        if (deleteTarget.value?.slug === editingServerSlug.value) {
          editingServerSlug.value = null;
          activeRightPage.value = 'server_detail';
        }
        deleteTarget.value = null;
      },
      onFinish: () => {
        isDeleting.value = false;
      },
      onError: (errors) =>
        handleActionError(errors as Record<string, string | undefined>),
    },
  );
}
</script>

<template>
  <AppLayout>
    <Head :title="t('MCP 服务')" />

    <WorkspaceSettingsLayout>
      <div
        class="flex h-[calc(100svh-7rem)] flex-col space-y-6 overflow-hidden md:h-[calc(100svh-4rem)]"
      >
        <HeadingSmall
          :title="t('MCP 服务')"
          :description="t('用 MCP 协议接入外部能力，供不同业务场景调用')"
        />

        <div class="flex min-h-0 flex-1 rounded-xl border">
          <div class="flex w-64 shrink-0 flex-col border-r">
            <div class="flex items-center justify-between p-4">
              <h3 class="text-sm font-semibold">
                {{ t('MCP 服务') }}
              </h3>
              <Button
                type="button"
                :variant="
                  activeRightPage === 'server_form' &&
                  serverFormMode === 'create'
                    ? 'secondary'
                    : 'ghost'
                "
                size="icon"
                class="h-7 w-7"
                :aria-label="t('添加 MCP 服务')"
                @click="openCreateForm"
              >
                <Plus class="h-4 w-4" />
              </Button>
            </div>

            <div class="flex-1 overflow-y-auto px-2 pb-4">
              <div
                v-if="props.servers.length === 0"
                class="px-4 py-8 text-center text-sm text-muted-foreground"
              >
                {{ t('暂无 MCP 服务') }}
              </div>

              <div v-else class="space-y-0.5">
                <div
                  v-for="server in props.servers"
                  :key="server.slug"
                  class="flex items-center gap-2 rounded-md text-sm transition-colors"
                  :class="
                    isServerRowActive(server)
                      ? 'bg-accent text-accent-foreground'
                      : 'hover:bg-muted'
                  "
                >
                  <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center gap-3 py-2 pl-3 text-left"
                    @click="selectServer(server)"
                  >
                    <Server class="h-5 w-5 shrink-0 text-muted-foreground" />
                    <div class="min-w-0 flex-1 space-y-0.5">
                      <span class="block truncate font-medium">
                        {{ server.name }}
                      </span>
                      <span
                        class="block truncate text-[11px] text-muted-foreground"
                      >
                        {{ server.tools_count }}
                        {{ t('工具数') }}
                      </span>
                    </div>
                  </button>
                  <Switch
                    class="mr-3"
                    :model-value="server.is_active"
                    :title="server.is_active ? t('停用') : t('启用')"
                    @update:model-value="() => toggleServer(server)"
                  />
                </div>
              </div>
            </div>
          </div>

          <div class="flex-1 overflow-y-auto">
            <div v-if="activeRightPage === 'server_form'" class="space-y-6 p-6">
              <McpServerFormPanel
                :mode="serverFormMode"
                :server="serverFormMode === 'edit' ? editingServer : null"
                :transport-options="props.transport_options"
                @cancel="closeServerForm"
                @saved="handleServerFormSaved"
              />
            </div>

            <template v-else-if="selectedServer">
              <div class="space-y-6 p-6">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0 space-y-2">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                      <h3 class="truncate text-sm font-semibold">
                        {{ selectedServer.name }}
                      </h3>
                      <Badge variant="outline">
                        {{ selectedServer.transport_label }}
                      </Badge>
                    </div>
                    <p class="text-sm break-all text-muted-foreground">
                      {{ selectedServer.endpoint_url }}
                    </p>
                  </div>

                  <div class="flex shrink-0 items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      :disabled="isCheckingSavedServer"
                      @click="checkSavedServerConnection"
                    >
                      <LoaderCircle
                        v-if="isCheckingSavedServer"
                        class="mr-2 h-4 w-4 animate-spin"
                      />
                      {{ t('测试') }}
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      @click="openEditForm(selectedServer)"
                    >
                      {{ t('编辑') }}
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      class="text-destructive hover:text-destructive"
                      :title="t('删除')"
                      :aria-label="t('删除')"
                      @click="openDeleteDialog(selectedServer)"
                    >
                      <Trash2 class="h-4 w-4" />
                    </Button>
                  </div>
                </div>

                <Separator />

                <div class="space-y-3">
                  <h3 class="text-sm font-semibold">
                    {{ t('连接配置') }}
                  </h3>
                  <div class="space-y-3 text-sm">
                    <div class="flex items-start gap-3">
                      <div class="w-24 shrink-0 text-xs text-muted-foreground">
                        {{ t('认证方式') }}
                      </div>
                      <div class="min-w-0 flex-1 break-words">
                        {{
                          selectedServer.has_auth_credentials
                            ? (selectedServer.auth_header_name ?? t('已配置'))
                            : t('不认证')
                        }}
                      </div>
                    </div>
                    <div class="flex items-start gap-3">
                      <div class="w-24 shrink-0 text-xs text-muted-foreground">
                        {{ t('超时（秒）') }}
                      </div>
                      <div class="min-w-0 flex-1 break-words">
                        {{ selectedServer.timeout_seconds }}
                      </div>
                    </div>
                  </div>
                </div>

                <Separator />

                <div class="space-y-3">
                  <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                      <h3 class="text-sm font-semibold">
                        {{ t('工具数') }}
                        ({{ selectedServer.tools_count }})
                      </h3>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="isSyncing"
                        @click="syncTools"
                      >
                        <LoaderCircle
                          v-if="isSyncing"
                          class="mr-2 h-4 w-4 animate-spin"
                        />
                        {{ t('同步') }}
                      </Button>
                      <Badge
                        v-if="selectedServer.last_sync_status === 'failed'"
                        variant="destructive"
                      >
                        {{ selectedServer.last_sync_status_label }}
                      </Badge>
                      <span
                        v-if="formattedLastSync"
                        class="text-xs text-muted-foreground"
                      >
                        {{ t('最后同步时间') }}:
                        {{ formattedLastSync }}
                      </span>
                    </div>
                    <p
                      v-if="
                        selectedServer.last_sync_status === 'failed' &&
                        selectedServer.last_sync_error
                      "
                      class="text-xs text-destructive"
                    >
                      {{ selectedServer.last_sync_error }}
                    </p>
                  </div>

                  <div v-if="selectedServer.tools.length > 0" class="space-y-2">
                    <McpToolListItem
                      v-for="tool in selectedServer.tools"
                      :key="tool.id"
                      :tool="tool"
                    />
                  </div>
                  <div v-else class="text-sm text-muted-foreground">
                    {{ t('该 MCP 服务暂无工具') }}
                  </div>
                </div>
              </div>
            </template>

            <div
              v-else
              class="flex h-full items-center justify-center text-sm text-muted-foreground"
            >
              {{ t('暂无 MCP 服务') }}
            </div>
          </div>
        </div>

        <ConfirmDeleteDialog
          :open="deleteTarget !== null"
          :title="
            t('删除 MCP 服务 “{name}”？', { name: deleteTarget?.name ?? '' })
          "
          :detail-description="
            t('删除后将同时移除已缓存的 {count} 个工具记录。', {
              count: deleteTarget?.tools_count ?? 0,
            })
          "
          :processing="isDeleting"
          @update:open="closeDeleteDialog"
          @confirm="confirmDelete"
        />
      </div>
    </WorkspaceSettingsLayout>
  </AppLayout>
</template>
