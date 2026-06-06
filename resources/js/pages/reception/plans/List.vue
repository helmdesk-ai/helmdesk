<!--
  文件说明：接待方案列表页，以表格承接方案列表与删除操作；
  右上角提供创建接待方案与回收站入口，「配置」进入详情页编辑。
  消费后端 ShowReceptionPlanListPagePropsData。
-->
<script setup lang="ts">
import Plan from '@/actions/App/Actions/Reception/Plan';
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
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ShowReceptionPlanListPagePropsData } from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MoreHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<ShowReceptionPlanListPagePropsData>();
const { t } = useI18n();

const deleteForm = useForm({});
const deletingPlanId = ref<string | null>(null);

const buildPlanListPageUrl = (page: number): string =>
  Plan.ShowReceptionPlanIndexPageAction.url({
    query: { page },
  });

const selectedPlan = computed(
  () =>
    props.plan_list.find((plan) => plan.id === deletingPlanId.value) ?? null,
);

const confirmDelete = () => {
  if (!selectedPlan.value || deleteForm.processing) {
    return;
  }

  deleteForm.delete(
    Plan.DeleteReceptionPlanAction.url({
      plan: selectedPlan.value.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        deletingPlanId.value = null;
      },
    },
  );
};

const handleDeleteDialogOpenChange = (open: boolean) => {
  if (!open) {
    deletingPlanId.value = null;
  }
};
</script>

<template>
  <AppLayout>
    <Head :title="t('接待方案')" />

    <div class="px-4 py-6 sm:px-6">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('接待方案')"
            :description="t('管理系统接待方案配置，保存即生效。')"
          />

          <div class="flex items-center gap-2">
            <Button as-child>
              <Link :href="Plan.ShowCreateReceptionPlanPageAction.url()">
                {{ t('创建接待方案') }}
              </Link>
            </Button>
            <Button variant="outline" as-child>
              <Link :href="Plan.ListReceptionPlanTrashAction.url()">
                {{ t('回收站') }}
              </Link>
            </Button>
          </div>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('方案名称') }}</th>
                  <th class="px-4 py-3">{{ t('对外昵称') }}</th>
                  <th class="px-4 py-3">{{ t('描述') }}</th>
                  <th class="px-4 py-3">{{ t('服务场景') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="plan in props.plan_list"
                  :key="plan.id"
                  class="border-t bg-background align-middle"
                >
                  <td class="px-4 py-3">
                    <span class="font-medium">{{ plan.name }}</span>
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    {{ plan.persona_config.display_name }}
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    <span v-if="plan.description" class="line-clamp-1">
                      {{ plan.description }}
                    </span>
                    <span v-else>—</span>
                  </td>

                  <td class="px-4 py-3 text-muted-foreground">
                    {{
                      t('{count} 个', { count: plan.service_scenarios_count })
                    }}
                  </td>

                  <td class="px-4 py-3">
                    <div class="flex justify-end gap-2">
                      <Button size="sm" variant="outline" as-child>
                        <Link
                          :href="
                            Plan.ShowReceptionPlanDetailPageAction.url({
                              plan: plan.id,
                            })
                          "
                        >
                          {{ t('配置') }}
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
                            @select="deletingPlanId = plan.id"
                          >
                            {{ t('删除') }}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>

                <tr v-if="props.plan_list.length === 0">
                  <td
                    colspan="5"
                    class="px-4 py-8 text-center text-muted-foreground"
                  >
                    {{ t('暂无接待方案') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div
            v-if="props.plan_list_pagination.last_page > 1"
            class="border-t p-4"
          >
            <PaginationNavigator
              :pagination="props.plan_list_pagination"
              :page-url="buildPlanListPageUrl"
            />
          </div>
        </div>

        <ConfirmDeleteDialog
          :open="deletingPlanId !== null"
          :title="t('确认删除接待方案？')"
          :detail-title="selectedPlan?.name"
          :detail-description="
            t(
              '删除后该接待方案会被移到回收站，可随时恢复；如果已有渠道或会话正在使用，系统会先阻止删除。',
            )
          "
          :processing="deleteForm.processing"
          @update:open="handleDeleteDialogOpenChange"
          @confirm="confirmDelete"
        />
      </div>
    </div>
  </AppLayout>
</template>
