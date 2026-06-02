<!--
  文件说明：标签回收站页面，承接已删除标签查看和恢复。
-->
<script setup lang="ts">
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import RestoreConfirmDialog from '@/components/common/RestoreConfirmDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import admin from '@/routes/admin';
import type {
  ListTagItemData,
  ShowTagTrashPagePropsData,
} from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<ShowTagTrashPagePropsData>();
const { t } = useI18n();
const { formatDateTime } = useDateTime();
const restoreForm = useForm({});
const restoringTagId = ref<string | null>(null);

const buildTagTrashPageUrl = (page: number): string => {
  return admin.manage.tags.trash.url({
    query: { page },
  });
};

const restoreErrorMessage = (): string | undefined => {
  const errors = restoreForm.errors as Record<string, string | undefined>;

  return errors.tag;
};

const submitRestore = (tag: ListTagItemData) => {
  restoringTagId.value = tag.id;
  restoreForm.clearErrors();

  restoreForm.put(
    admin.manage.tags.restore.url({
      id: tag.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        restoreForm.clearErrors();
      },
      onFinish: () => {
        restoringTagId.value = null;
      },
    },
  );
};
</script>

<template>
  <AppLayout>
    <Head :title="t('标签回收站')" />

    <SystemSettingsLayout>
      <section class="mx-auto w-full max-w-none space-y-12">
        <div class="space-y-6">
          <div class="flex items-start justify-between gap-4">
            <HeadingSmall
              :title="t('标签回收站')"
              :description="t('查看已删除的标签并可恢复')"
            />

            <Button variant="outline" class="shrink-0" as-child>
              <Link :href="admin.manage.tags.index.url()">
                {{ t('返回列表') }}
              </Link>
            </Button>
          </div>

          <div class="rounded-lg border">
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="border-b bg-muted/30 text-muted-foreground">
                  <tr class="text-left">
                    <th class="px-4 py-3">{{ t('名称') }}</th>
                    <th class="px-4 py-3">{{ t('维度') }}</th>
                    <th class="px-4 py-3">{{ t('颜色') }}</th>

                    <th class="px-4 py-3">{{ t('来源') }}</th>
                    <th class="px-4 py-3">{{ t('使用数') }}</th>
                    <th class="px-4 py-3">{{ t('删除时间') }}</th>
                    <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="tag in props.trashed_tag_list"
                    :key="tag.id"
                    class="border-b last:border-b-0"
                  >
                    <td class="px-4 py-3 font-medium">
                      {{ tag.name }}
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">
                      <span v-if="tag.scope_label">
                        {{ tag.scope_label }}
                        <span v-if="tag.tag_group_name" class="text-xs">
                          · {{ tag.tag_group_name }}
                        </span>
                      </span>
                      <span v-else>-</span>
                    </td>
                    <td class="px-4 py-3">
                      <Badge
                        class="flex w-fit items-center gap-1.5 border bg-background text-foreground shadow-sm"
                      >
                        <span
                          class="h-2 w-2 shrink-0 rounded-full"
                          :style="{ backgroundColor: tag.color ?? '#94a3b8' }"
                        />
                        {{ tag.color ?? '-' }}
                      </Badge>
                    </td>

                    <td class="px-4 py-3 text-muted-foreground">
                      {{ tag.source_label }}
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">
                      {{ tag.usage_count }}
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">
                      {{
                        tag.deleted_at ? formatDateTime(tag.deleted_at) : '-'
                      }}
                    </td>
                    <td class="px-4 py-3 text-right">
                      <RestoreConfirmDialog
                        :title="t('确认恢复标签？')"
                        :processing="restoreForm.processing"
                        :submitting="
                          restoreForm.processing && restoringTagId === tag.id
                        "
                        :error-message="restoreErrorMessage()"
                        @update:open="restoreForm.clearErrors()"
                        @confirm="submitRestore(tag)"
                      >
                        <div class="font-medium">{{ tag.name }}</div>
                        <div class="text-muted-foreground">
                          {{ t('恢复后将重新出现在标签列表中。') }}
                        </div>
                      </RestoreConfirmDialog>
                    </td>
                  </tr>

                  <tr v-if="props.trashed_tag_list.length === 0">
                    <td
                      colspan="7"
                      class="px-4 py-8 text-center text-muted-foreground"
                    >
                      {{ t('暂无已删除的标签') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div
              v-if="props.trashed_tag_list_pagination.last_page > 1"
              class="border-t p-4"
            >
              <PaginationNavigator
                :pagination="props.trashed_tag_list_pagination"
                :page-url="buildTagTrashPageUrl"
              />
            </div>
          </div>
        </div>
      </section>
    </SystemSettingsLayout>
  </AppLayout>
</template>
