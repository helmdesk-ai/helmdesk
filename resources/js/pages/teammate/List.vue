<!--
  文件说明：团队成员页面，承接成员列表、邀请、创建和编辑流程。
-->
<script setup lang="ts">
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import workspace from '@/routes/workspace';
import type { ShowListTeammatePagePropsData } from '@/types/generated';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { MoreHorizontal } from 'lucide-vue-next';
import { ref } from 'vue';

type TeammateRow = ShowListTeammatePagePropsData['user_list'][number];

/*
 * 与后端 App\Enums\UserOnlineStatus 对齐：Online = 1，Offline = 0。
 * 这里用 const 而不是从 props 推导，是因为列表里的 Switch 在切换时
 * 需要一个稳定的目标值（不依赖当前行的状态枚举顺序）。
 */
const ONLINE_STATUS_ONLINE = 1;
const ONLINE_STATUS_OFFLINE = 0;

const { t } = useI18n();
const props = defineProps<ShowListTeammatePagePropsData>();
const currentWorkspace = useRequiredWorkspace();
const updatingStatusIds = ref<Record<string, boolean>>({});
const removeForm = useForm({});
const removingTeammate = ref<TeammateRow | null>(null);
const { formatDateTime } = useDateTime();

const openRemoveDialog = (user: TeammateRow) => {
  removingTeammate.value = user;
};

const confirmRemoveTeammate = () => {
  const target = removingTeammate.value;
  if (!target) return;

  removeForm.delete(
    workspace.manage.teammates.destroy.url({
      slug: currentWorkspace.value.slug,
      id: target.user_id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        removingTeammate.value = null;
      },
    },
  );
};
const handleOnlineStatusChange = (userId: string, status: number) => {
  updatingStatusIds.value[userId] = true;
  router.put(
    workspace.manage.teammates.onlineStatus.update.url({
      slug: currentWorkspace.value.slug,
      id: userId,
    }),
    { online_status: Number(status) },
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        updatingStatusIds.value[userId] = false;
      },
    },
  );
};
</script>

<template>
  <AppLayout>
    <Head :title="t('客服')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall :title="t('客服')" :description="t('管理客服账号')" />

          <div class="inline-flex items-center gap-2">
            <Button as-child>
              <Link
                :href="
                  workspace.manage.teammates.create.url(currentWorkspace.slug)
                "
              >
                {{ t('新增客服') }}
              </Link>
            </Button>
          </div>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('头像') }}</th>
                  <th class="px-4 py-3">{{ t('名称') }}</th>
                  <th class="px-4 py-3">{{ t('对外昵称') }}</th>
                  <th class="px-4 py-3">{{ t('邮箱') }}</th>
                  <th class="px-4 py-3">{{ t('身份') }}</th>
                  <th class="px-4 py-3">{{ t('在线状态') }}</th>
                  <th class="px-4 py-3">{{ t('最后活跃时间') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="u in props.user_list"
                  :key="u.user_id"
                  class="border-t bg-background"
                >
                  <td class="px-4 py-3">
                    <Avatar class="h-9 w-9">
                      <AvatarImage v-if="u.user_avatar" :src="u.user_avatar" />
                      <AvatarFallback>
                        {{ (u.user_name || '').slice(0, 1) }}
                      </AvatarFallback>
                    </Avatar>
                  </td>
                  <td class="px-4 py-3 font-medium">
                    {{ u.user_name }}
                  </td>
                  <td class="px-4 py-3">
                    {{ u.user_nickname || '-' }}
                  </td>
                  <td class="px-4 py-3">
                    {{ u.user_email }}
                  </td>
                  <td class="px-4 py-3">
                    {{ u.role.label }}
                  </td>
                  <td class="px-4 py-3">
                    <Switch
                      :model-value="
                        Number(u.user_online_status.value) ===
                        ONLINE_STATUS_ONLINE
                      "
                      :disabled="updatingStatusIds[u.user_id]"
                      :aria-label="u.user_online_status.label"
                      @update:model-value="
                        (checked) =>
                          handleOnlineStatusChange(
                            u.user_id,
                            checked
                              ? ONLINE_STATUS_ONLINE
                              : ONLINE_STATUS_OFFLINE,
                          )
                      "
                    />
                  </td>
                  <td class="px-4 py-3">
                    {{
                      u.user_last_active_at
                        ? formatDateTime(u.user_last_active_at)
                        : '-'
                    }}
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex justify-end gap-2">
                      <Button as-child variant="outline" size="sm">
                        <Link
                          :href="
                            workspace.manage.teammates.edit.url({
                              slug: currentWorkspace.slug,
                              id: u.user_id,
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
                            :disabled="
                              !u.show_remove_button || removeForm.processing
                            "
                            @select="openRemoveDialog(u)"
                          >
                            {{ t('移除') }}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>

                <tr v-if="props.user_list.length === 0">
                  <td
                    class="px-4 py-8 text-center text-muted-foreground"
                    colspan="7"
                  >
                    {{ t('暂无客服') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <Dialog
        :open="removingTeammate !== null"
        @update:open="(open) => !open && (removingTeammate = null)"
      >
        <DialogContent>
          <DialogHeader class="space-y-3">
            <DialogTitle>{{ t('确认移除客服？') }}</DialogTitle>
          </DialogHeader>

          <div
            v-if="removingTeammate"
            class="rounded-md bg-muted/30 p-3 text-sm"
          >
            <div class="font-medium">{{ removingTeammate.user_name }}</div>
            <div class="mt-1 text-muted-foreground">
              {{ t('将从当前工作区移除该成员的访问权限（不会删除用户）。') }}
            </div>
          </div>

          <DialogFooter class="gap-2">
            <DialogClose as-child>
              <Button variant="secondary" :disabled="removeForm.processing">
                {{ t('取消') }}
              </Button>
            </DialogClose>
            <Button
              variant="destructive"
              :disabled="
                removeForm.processing || !removingTeammate?.show_remove_button
              "
              @click="confirmRemoveTeammate"
            >
              {{ removeForm.processing ? t('移除中...') : t('确认移除') }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
