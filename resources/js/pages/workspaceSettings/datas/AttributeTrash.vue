<!--
  文件说明：自定义属性回收站页面，承接已删除属性查看和恢复。
-->
<script setup lang="ts">
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import RestoreConfirmDialog from '@/components/common/RestoreConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import WorkspaceSettingsLayout from '@/layouts/WorkspaceSettingsLayout.vue';
import workspace from '@/routes/workspace';
import type {
  ListAttributeDefinitionItemData,
  ShowAttributeDefinitionTrashPagePropsData,
} from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<ShowAttributeDefinitionTrashPagePropsData>();
const { t } = useI18n();
const { formatDateTime } = useDateTime();
const currentWorkspace = useRequiredWorkspace();
const restoreForm = useForm({});
const restoringDefinitionId = ref<string | null>(null);

const buildAttributeTrashPageUrl = (page: number): string => {
  return workspace.manage.attributes.trash.url(currentWorkspace.value.slug, {
    query: { page },
  });
};

const restoreErrorMessage = (): string | undefined => {
  const errors = restoreForm.errors as Record<string, string | undefined>;

  return errors.definition;
};

const submitRestore = (definition: ListAttributeDefinitionItemData) => {
  restoringDefinitionId.value = definition.id;
  restoreForm.clearErrors();

  restoreForm.put(
    workspace.manage.attributes.restore.url({
      slug: currentWorkspace.value.slug,
      id: definition.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        restoreForm.clearErrors();
      },
      onFinish: () => {
        restoringDefinitionId.value = null;
      },
    },
  );
};
</script>

<template>
  <AppLayout>
    <Head :title="t('自定义属性回收站')" />

    <WorkspaceSettingsLayout>
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('自定义属性回收站')"
            :description="t('查看已删除的自定义属性并可恢复')"
          />

          <Button variant="outline" class="shrink-0" as-child>
            <Link
              :href="
                workspace.manage.attributes.index.url(currentWorkspace.slug)
              "
            >
              {{ t('返回列表') }}
            </Link>
          </Button>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('属性名称') }}</th>
                  <th class="px-4 py-3">{{ t('属性标识') }}</th>
                  <th class="px-4 py-3">{{ t('属性类型') }}</th>
                  <th class="px-4 py-3">{{ t('使用数') }}</th>
                  <th class="px-4 py-3">{{ t('删除时间') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="definition in props.trashed_definition_list"
                  :key="definition.id"
                  class="border-b last:border-b-0"
                >
                  <td class="px-4 py-3 font-medium">
                    {{ definition.name }}
                  </td>
                  <td class="px-4 py-3">
                    <code class="rounded bg-muted px-1.5 py-0.5 text-xs">
                      {{ definition.key }}
                    </code>
                  </td>
                  <td class="px-4 py-3 text-muted-foreground">
                    {{ definition.type_label }}
                  </td>
                  <td class="px-4 py-3 text-muted-foreground">
                    {{ definition.usage_count }}
                  </td>
                  <td class="px-4 py-3 text-muted-foreground">
                    {{
                      definition.deleted_at
                        ? formatDateTime(definition.deleted_at)
                        : '-'
                    }}
                  </td>
                  <td class="px-4 py-3 text-right">
                    <RestoreConfirmDialog
                      :title="t('确认恢复属性？')"
                      :processing="restoreForm.processing"
                      :submitting="
                        restoreForm.processing &&
                        restoringDefinitionId === definition.id
                      "
                      :error-message="restoreErrorMessage()"
                      @update:open="restoreForm.clearErrors()"
                      @confirm="submitRestore(definition)"
                    >
                      <div class="font-medium">
                        {{ definition.name }}
                      </div>
                      <div class="mt-1 text-muted-foreground">
                        {{ t('恢复后将重新出现在自定义属性列表中。') }}
                      </div>
                    </RestoreConfirmDialog>
                  </td>
                </tr>

                <tr v-if="props.trashed_definition_list.length === 0">
                  <td
                    colspan="6"
                    class="px-4 py-8 text-center text-muted-foreground"
                  >
                    {{ t('暂无已删除的属性') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div
            v-if="props.trashed_definition_list_pagination.last_page > 1"
            class="border-t p-4"
          >
            <PaginationNavigator
              :pagination="props.trashed_definition_list_pagination"
              :page-url="buildAttributeTrashPageUrl"
            />
          </div>
        </div>
      </div>
    </WorkspaceSettingsLayout>
  </AppLayout>
</template>
