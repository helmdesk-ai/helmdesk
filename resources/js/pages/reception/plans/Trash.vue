<!--
  文件说明：接待方案回收站页，承接已删除方案的查看与恢复；
  消费后端 ListReceptionPlanTrashPagePropsData。
-->
<script setup lang="ts">
import Plan from '@/actions/App/Actions/Reception/Plan';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import RestoreConfirmDialog from '@/components/common/RestoreConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ListReceptionPlanTrashPagePropsData } from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<ListReceptionPlanTrashPagePropsData>();
const { t } = useI18n();
const { formatDateTime } = useDateTime();
const restoreForm = useForm({});

const buildTrashPageUrl = (page: number): string =>
  Plan.ListReceptionPlanTrashAction.url({
    query: { page },
  });
</script>

<template>
  <AppLayout>
    <Head :title="t('接待方案回收站')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('接待方案回收站')"
            :description="t('查看已删除的接待方案并可恢复。')"
          />

          <Button variant="outline" class="shrink-0" as-child>
            <Link :href="Plan.ShowReceptionPlanIndexPageAction.url()">
              {{ t('返回列表') }}
            </Link>
          </Button>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('方案名称') }}</th>
                  <th class="px-4 py-3">{{ t('删除时间') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="plan in props.trashed_plan_list"
                  :key="plan.id"
                  class="border-b last:border-b-0"
                >
                  <td class="px-4 py-3">
                    <div class="font-medium">{{ plan.name }}</div>
                  </td>
                  <td class="px-4 py-3 text-muted-foreground">
                    {{
                      plan.deleted_at ? formatDateTime(plan.deleted_at) : '—'
                    }}
                  </td>
                  <td class="px-4 py-3 text-right">
                    <RestoreConfirmDialog
                      :title="t('确认恢复接待方案？')"
                      :description="t('恢复后将重新出现在接待方案列表中。')"
                      :processing="restoreForm.processing"
                      :submitting="restoreForm.processing"
                      @confirm="
                        restoreForm.put(
                          Plan.RestoreReceptionPlanAction.url({
                            plan: plan.id,
                          }),
                          { preserveScroll: true },
                        )
                      "
                    >
                      <div class="font-medium">{{ plan.name }}</div>
                    </RestoreConfirmDialog>
                  </td>
                </tr>

                <tr v-if="props.trashed_plan_list.length === 0">
                  <td
                    colspan="3"
                    class="px-4 py-8 text-center text-muted-foreground"
                  >
                    {{ t('暂无已删除的接待方案') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div
            v-if="props.trashed_plan_list_pagination.last_page > 1"
            class="border-t p-4"
          >
            <PaginationNavigator
              :pagination="props.trashed_plan_list_pagination"
              :page-url="buildTrashPageUrl"
            />
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
