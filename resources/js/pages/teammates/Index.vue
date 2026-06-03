<!--
  文件说明：客服管理列表页面，承接客服账号展示、两步验证重置和删除操作。
  消费后端 ShowTeammateListPagePropsData。
-->
<script setup lang="ts">
import Teammate from '@/actions/App/Actions/Teammate';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
  ListTeammateItemData,
  ShowTeammateListPagePropsData,
} from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MoreHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<ShowTeammateListPagePropsData>();

const { t } = useI18n();
const { formatDateTime } = useDateTime();

const deleteForm = useForm({});
const resetTwoFactorForm = useForm({});
const deletingTeammateId = ref<string | null>(null);

const deletingTeammate = computed(
  () =>
    props.user_list.find((user) => user.id === deletingTeammateId.value) ??
    null,
);

function openDeleteDialog(user: ListTeammateItemData): void {
  deletingTeammateId.value = user.id;
}

function handleDeleteDialogOpenChange(open: boolean): void {
  if (!open) {
    deletingTeammateId.value = null;
  }
}

function confirmDelete(): void {
  if (!deletingTeammate.value || deleteForm.processing) {
    return;
  }

  deleteForm.delete(
    Teammate.DeleteTeammateAction.url({
      teammate: deletingTeammate.value.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deletingTeammateId.value = null;
      },
    },
  );
}

function resetTwoFactor(user: ListTeammateItemData): void {
  if (resetTwoFactorForm.processing) {
    return;
  }

  resetTwoFactorForm.put(
    Teammate.ResetTeammateTwoFactorAuthenticationAction.url({
      teammate: user.id,
    }),
    { preserveScroll: true },
  );
}
</script>

<template>
  <AppLayout>
    <Head :title="t('客服管理')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('客服管理')"
            :description="t('管理可登录后台并参与会话接待的客服账号。')"
          />

          <Button v-if="props.can_create" as-child>
            <Link :href="Teammate.ShowCreateTeammatePageAction.url()">
              {{ t('新增客服') }}
            </Link>
          </Button>
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
                  <th class="px-4 py-3">{{ t('权限数') }}</th>
                  <th class="px-4 py-3">{{ t('在线状态') }}</th>
                  <th class="px-4 py-3">{{ t('最后活跃时间') }}</th>
                  <th class="px-4 py-3">{{ t('两步验证') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="user in props.user_list"
                  :key="user.id"
                  class="border-t bg-background align-middle"
                >
                  <td class="px-4 py-3">
                    <Avatar class="h-9 w-9">
                      <AvatarImage v-if="user.avatar" :src="user.avatar" />
                      <AvatarFallback>
                        {{ (user.name || '').slice(0, 1) }}
                      </AvatarFallback>
                    </Avatar>
                  </td>

                  <td class="px-4 py-3 font-medium">
                    {{ user.name }}
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    {{ user.nickname || '-' }}
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    {{ user.email }}
                  </td>

                  <td class="px-4 py-3">
                    <Badge variant="secondary">
                      {{ user.permission_count }}
                    </Badge>
                  </td>

                  <td class="px-4 py-3">
                    <Badge
                      :variant="
                        Number(user.online_status) === 1
                          ? 'default'
                          : 'secondary'
                      "
                    >
                      {{ user.online_status_label }}
                    </Badge>
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    {{
                      user.last_active_at
                        ? formatDateTime(user.last_active_at)
                        : '-'
                    }}
                  </td>

                  <td class="px-4 py-3">
                    <Badge
                      :variant="
                        user.two_factor_enabled ? 'default' : 'secondary'
                      "
                    >
                      {{ user.two_factor_enabled ? t('已启用') : t('未启用') }}
                    </Badge>
                  </td>

                  <td class="px-4 py-3">
                    <div class="flex justify-end gap-2">
                      <Button
                        v-if="user.can_edit"
                        size="sm"
                        variant="outline"
                        as-child
                      >
                        <Link
                          :href="
                            Teammate.ShowEditTeammatePageAction.url({
                              teammate: user.id,
                            })
                          "
                        >
                          {{ t('编辑') }}
                        </Link>
                      </Button>

                      <DropdownMenu
                        v-if="
                          user.can_delete ||
                          (user.can_reset_two_factor && user.two_factor_enabled)
                        "
                      >
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
                        <DropdownMenuContent align="end" class="w-40">
                          <DropdownMenuItem
                            v-if="
                              user.can_reset_two_factor &&
                              user.two_factor_enabled
                            "
                            :disabled="resetTwoFactorForm.processing"
                            @select="resetTwoFactor(user)"
                          >
                            {{ t('重置两步验证') }}
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            v-if="user.can_delete"
                            class="text-destructive focus:text-destructive"
                            :disabled="deleteForm.processing"
                            @select="openDeleteDialog(user)"
                          >
                            {{ t('删除') }}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>

                <tr v-if="props.user_list.length === 0">
                  <td
                    class="px-4 py-8 text-center text-muted-foreground"
                    colspan="9"
                  >
                    {{ t('暂无客服') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <ConfirmDeleteDialog
        :open="deletingTeammate !== null"
        :title="t('确认删除客服？')"
        :detail-title="deletingTeammate?.name"
        :detail-description="t('将该客服账号放入回收站，可以后续恢复。')"
        :processing="deleteForm.processing"
        @update:open="handleDeleteDialogOpenChange"
        @confirm="confirmDelete"
      />
    </div>
  </AppLayout>
</template>
