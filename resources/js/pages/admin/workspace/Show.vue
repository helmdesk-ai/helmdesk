<!--
  文件说明：系统工作区管理页面，承接工作区列表、创建、编辑、详情和回收站。
-->
<script setup lang="ts">
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import admin from '@/routes/admin';
import type {
  FormAddWorkspaceMemberData,
  ShowWorkspaceDetailPagePropsData,
  WorkspaceRole,
} from '@/types/generated';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
const { t } = useI18n();
const { formatDateTime } = useDateTime();
const props = defineProps<ShowWorkspaceDetailPagePropsData>();

const showAddDialog = ref(false);
const addForm = useForm<FormAddWorkspaceMemberData>({
  user_id: '',
  role: 'operator' as WorkspaceRole,
});
const deleteForm = useForm({});
const deletingMember = ref<{
  id: string;
  name: string;
  email: string;
} | null>(null);

watch(
  () => props.available_users,
  (opts) => {
    if (!addForm.user_id && opts?.length) {
      addForm.user_id = String(opts[0].id);
    }
  },
  { immediate: true },
);

watch(
  () => props.role_options,
  (opts) => {
    if (!addForm.role && opts?.length) {
      addForm.role = opts[0].value as WorkspaceRole;
    }
  },
  { immediate: true },
);

const buildWorkspaceMembersPageUrl = (page: number): string => {
  return admin.workspaces.show.url(props.workspace.id, {
    query: { page },
  });
};

const isOwner = (userId: string) =>
  !!props.workspace.owner?.id &&
  String(props.workspace.owner.id) === String(userId);

const openDeleteDialog = (member: {
  id: string;
  name: string;
  email: string;
}) => {
  deletingMember.value = member;
};

const closeDeleteDialog = (open: boolean) => {
  if (open || deleteForm.processing) {
    return;
  }

  deletingMember.value = null;
};

const submitAddMember = () => {
  addForm.post(admin.workspaces.members.store.url(props.workspace.id), {
    preserveScroll: true,
    onSuccess: () => {
      showAddDialog.value = false;
      addForm.reset();
      addForm.clearErrors();
    },
  });
};

const submitDelete = () => {
  if (!deletingMember.value) {
    return;
  }

  deleteForm.delete(
    admin.workspaces.members.destroy.url({
      id: props.workspace.id,
      userId: deletingMember.value.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deletingMember.value = null;
      },
    },
  );
};
</script>

<template>
  <SystemAppLayout>
    <Head :title="t('客服列表')" />
    <div class="px-4 py-6 sm:px-6">
      <div class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <div class="flex items-start justify-between gap-4">
            <HeadingSmall
              :title="props.workspace.name"
              :description="t('查看并管理该工作区的客服与管理员')"
            />
            <div class="flex items-center gap-2">
              <Dialog v-model:open="showAddDialog">
                <DialogTrigger as-child>
                  <Button :disabled="props.available_users.length === 0">
                    {{ t('添加客服') }}
                  </Button>
                </DialogTrigger>
                <DialogContent>
                  <DialogHeader class="space-y-3">
                    <DialogTitle>{{ t('添加客服') }}</DialogTitle>
                    <DialogDescription>
                      {{ t('选择用户并指定其身份为客服或管理员') }}
                    </DialogDescription>
                  </DialogHeader>

                  <form class="space-y-5" @submit.prevent="submitAddMember">
                    <div class="grid gap-2">
                      <Label for="user_id">{{ t('用户') }}</Label>
                      <input
                        type="hidden"
                        name="user_id"
                        :value="addForm.user_id"
                      />
                      <Select v-model="addForm.user_id">
                        <SelectTrigger id="user_id" class="mt-1">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem
                            v-for="u in props.available_users"
                            :key="String(u.id)"
                            :value="String(u.id)"
                          >
                            {{ u.name }} ({{ u.email }})
                          </SelectItem>
                        </SelectContent>
                      </Select>
                      <p
                        v-if="props.available_users.length === 0"
                        class="text-sm text-muted-foreground"
                      >
                        {{ t('暂无可添加的用户') }}
                      </p>
                      <p
                        v-if="addForm.errors.user_id"
                        class="text-sm text-destructive"
                      >
                        {{ addForm.errors.user_id }}
                      </p>
                    </div>

                    <div class="grid gap-2">
                      <Label for="role">{{ t('身份') }}</Label>
                      <input type="hidden" name="role" :value="addForm.role" />
                      <Select v-model="addForm.role">
                        <SelectTrigger id="role" class="mt-1">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem
                            v-for="opt in props.role_options"
                            :key="String(opt.value)"
                            :value="String(opt.value)"
                          >
                            {{ opt.label }}
                          </SelectItem>
                        </SelectContent>
                      </Select>
                      <p
                        v-if="addForm.errors.role"
                        class="text-sm text-destructive"
                      >
                        {{ addForm.errors.role }}
                      </p>
                    </div>

                    <DialogFooter class="gap-2">
                      <DialogClose as-child>
                        <Button
                          type="button"
                          variant="secondary"
                          :disabled="addForm.processing"
                        >
                          {{ t('取消') }}
                        </Button>
                      </DialogClose>
                      <Button
                        type="submit"
                        :disabled="
                          addForm.processing ||
                          !addForm.user_id ||
                          !addForm.role
                        "
                      >
                        {{
                          addForm.processing ? t('添加中...') : t('确认添加')
                        }}
                      </Button>
                    </DialogFooter>
                  </form>
                </DialogContent>
              </Dialog>
            </div>
          </div>

          <div class="rounded-lg border">
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="border-b bg-muted/30 text-muted-foreground">
                  <tr>
                    <th class="px-4 py-3 text-left font-medium">
                      {{ t('成员') }}
                    </th>
                    <th class="px-4 py-3 text-left font-medium">
                      {{ t('邮箱') }}
                    </th>
                    <th class="px-4 py-3 text-left font-medium">
                      {{ t('角色') }}
                    </th>
                    <th class="px-4 py-3 text-left font-medium">
                      {{ t('加入时间') }}
                    </th>
                    <th class="px-4 py-3 text-right font-medium">
                      {{ t('操作') }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="m in props.members.items"
                    :key="m.id"
                    class="border-b last:border-b-0"
                  >
                    <td class="px-4 py-3 font-medium">
                      {{ m.name }}
                      <span
                        v-if="m.deleted_at"
                        class="ml-2 text-xs text-muted-foreground"
                      >
                        {{ t('已删除') }}
                      </span>
                      <span
                        v-if="isOwner(m.id)"
                        class="ml-2 rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                      >
                        {{ t('所有者') }}
                      </span>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">
                      {{ m.email }}
                    </td>
                    <td class="px-4 py-3">{{ m.role?.label || '-' }}</td>
                    <td class="px-4 py-3 text-muted-foreground">
                      {{ m.joined_at ? formatDateTime(m.joined_at) : '-' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                      <Button
                        v-if="!isOwner(m.id)"
                        variant="destructive"
                        size="sm"
                        :disabled="deleteForm.processing"
                        @click="
                          openDeleteDialog({
                            id: String(m.id),
                            name: m.name,
                            email: m.email,
                          })
                        "
                      >
                        {{ t('删除') }}
                      </Button>
                      <span v-else class="text-sm text-muted-foreground"
                        >-</span
                      >
                    </td>
                  </tr>

                  <tr v-if="props.members.items.length === 0">
                    <td
                      colspan="5"
                      class="px-4 py-8 text-center text-muted-foreground"
                    >
                      {{ t('暂无成员') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div
              v-if="props.members.pagination.last_page > 1"
              class="border-t p-4"
            >
              <PaginationNavigator
                :pagination="props.members.pagination"
                :page-url="buildWorkspaceMembersPageUrl"
              />
            </div>
          </div>
        </div>
      </div>
    </div>

    <ConfirmDeleteDialog
      :open="deletingMember !== null"
      :title="t('确认删除成员？')"
      :description="t('将从该工作区移除该成员的访问权限。')"
      :detail-title="deletingMember?.name"
      :detail-description="deletingMember?.email"
      :processing="deleteForm.processing"
      @update:open="closeDeleteDialog"
      @confirm="submitDelete"
    />
  </SystemAppLayout>
</template>
