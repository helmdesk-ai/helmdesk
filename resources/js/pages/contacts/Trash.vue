<!--
  文件说明：联系人回收站页面，承接已删除联系人列表和恢复操作。
-->
<script setup lang="ts">
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import RestoreConfirmDialog from '@/components/common/RestoreConfirmDialog.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import { useVisitorDisplay } from '@/composables/useVisitorDisplay';
import AppLayout from '@/layouts/AppLayout.vue';
import { getAvatarInitial } from '@/lib/initials';
import admin from '@/routes/admin';
import type {
  ShowContactTrashPagePropsData,
  TrashContactItemData,
} from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const { t } = useI18n();
const { formatDateTime } = useDateTime();
const { formatVisitorName } = useVisitorDisplay();
const props = defineProps<ShowContactTrashPagePropsData>();

const restoreForm = useForm({});
const restoringContactId = ref<string | null>(null);

const buildContactTrashPageUrl = (page: number): string => {
  return admin.contacts.trash.url({
    query: { page },
  });
};

const displayName = (contactItem: TrashContactItemData): string => {
  return formatVisitorName(contactItem.name, contactItem.id);
};

const displayIdentity = (contactItem: TrashContactItemData): string => {
  return contactItem.primary_email || contactItem.primary_phone || '-';
};

const nameInitial = (contactItem: TrashContactItemData): string =>
  getAvatarInitial(contactItem.name);

const typeBadgeVariant = (type: string): 'default' | 'secondary' =>
  type === 'contact' ? 'default' : 'secondary';

const restoreErrorMessage = (): string | undefined => {
  const errors = restoreForm.errors as Record<string, string | undefined>;

  return errors.contact;
};

const submitRestore = (contactItem: TrashContactItemData) => {
  restoringContactId.value = contactItem.id;
  restoreForm.clearErrors();

  restoreForm.put(
    admin.contacts.restore.url({
      id: contactItem.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        restoreForm.clearErrors();
      },
      onFinish: () => {
        restoringContactId.value = null;
      },
    },
  );
};
</script>

<template>
  <AppLayout>
    <Head :title="t('联系人回收站')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('联系人回收站')"
            :description="t('查看已删除的联系人并可恢复')"
          />

          <Button variant="outline" class="shrink-0" as-child>
            <Link
              :href="
                admin.contacts.index.url({
                  type: 'all',
                })
              "
            >
              {{ t('返回列表') }}
            </Link>
          </Button>
        </div>

        <div class="min-w-0 rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('名称') }}</th>
                  <th class="px-4 py-3">{{ t('身份标识') }}</th>
                  <th class="px-4 py-3">{{ t('类型') }}</th>
                  <th class="px-4 py-3">{{ t('来源') }}</th>
                  <th class="px-4 py-3">{{ t('创建时间') }}</th>
                  <th class="px-4 py-3">{{ t('删除时间') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="contactItem in props.contact_trash_list"
                  :key="contactItem.id"
                  class="border-b last:border-b-0"
                >
                  <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                      <Avatar class="h-8 w-8">
                        <AvatarImage :src="contactItem.avatar_url" />
                        <AvatarFallback class="text-xs">
                          {{ nameInitial(contactItem) }}
                        </AvatarFallback>
                      </Avatar>
                      <span class="font-medium">
                        {{ displayName(contactItem) }}
                      </span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-muted-foreground">
                    {{ displayIdentity(contactItem) }}
                  </td>
                  <td class="px-4 py-3">
                    <Badge
                      :variant="
                        typeBadgeVariant(String(contactItem.type.value))
                      "
                    >
                      {{ contactItem.type.label }}
                    </Badge>
                  </td>
                  <td class="px-4 py-3">
                    <Badge variant="secondary">
                      {{ contactItem.source.label }}
                    </Badge>
                  </td>
                  <td class="px-4 py-3 text-muted-foreground">
                    {{ formatDateTime(contactItem.created_at) }}
                  </td>
                  <td class="px-4 py-3 text-muted-foreground">
                    {{
                      contactItem.deleted_at
                        ? formatDateTime(contactItem.deleted_at)
                        : '-'
                    }}
                  </td>
                  <td
                    class="px-4 py-3 text-right whitespace-nowrap"
                    @click.stop
                  >
                    <RestoreConfirmDialog
                      :title="t('确认恢复联系人？')"
                      :processing="restoreForm.processing"
                      :submitting="
                        restoreForm.processing &&
                        restoringContactId === contactItem.id
                      "
                      :error-message="restoreErrorMessage()"
                      @update:open="restoreForm.clearErrors()"
                      @confirm="submitRestore(contactItem)"
                    >
                      <div class="font-medium">
                        {{ displayName(contactItem) }}
                      </div>
                      <div class="text-muted-foreground">
                        {{ displayIdentity(contactItem) }}
                      </div>
                      <div class="text-muted-foreground">
                        {{ t('恢复后将重新出现在联系人列表中。') }}
                      </div>
                    </RestoreConfirmDialog>
                  </td>
                </tr>

                <tr v-if="props.contact_trash_list.length === 0">
                  <td
                    colspan="7"
                    class="px-4 py-8 text-center text-muted-foreground"
                  >
                    {{ t('暂无已删除的联系人') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div
            v-if="props.contact_trash_list_pagination.last_page > 1"
            class="border-t p-4"
          >
            <PaginationNavigator
              :pagination="props.contact_trash_list_pagination"
              :page-url="buildContactTrashPageUrl"
            />
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
