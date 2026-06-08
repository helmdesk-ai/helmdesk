<!--
  文件说明：「AI 模型管理」列表页，按用途分 Tab（选中状态与 URL ?purpose= 关联，刷新保持）；
  每个 Tab 内用表格展示该用途下的模型，用上移/下移排主备（sort_order），并支持启用切换、编辑与删除。
  消费后端 ShowAiModelListPagePropsData。
-->
<script setup lang="ts">
import AiModel from '@/actions/App/Actions/AiModel';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type {
  AiModelListItemData,
  ShowAiModelListPagePropsData,
} from '@/types/generated';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, MoreHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<ShowAiModelListPagePropsData>();
const { t } = useI18n();
const page = usePage();

const tabValues = computed<string[]>(() =>
  props.purpose_tabs.map((tab) => String(tab.value)),
);

// 选中用途以 URL ?purpose= 为准（刷新/前进后退保持），非法值回落到首个 Tab。
const activePurpose = computed<string>(() => {
  const query = page.url.split('?')[1] ?? '';
  const purpose = new URLSearchParams(query).get('purpose') ?? '';
  return tabValues.value.includes(purpose)
    ? purpose
    : (tabValues.value[0] ?? '');
});

function selectPurpose(value: string): void {
  if (value === activePurpose.value) {
    return;
  }

  router.get(
    AiModel.ShowAiModelListAction.url(),
    { purpose: value },
    { preserveState: true, preserveScroll: true, replace: true },
  );
}

function modelsForPurpose(purpose: string): AiModelListItemData[] {
  return props.models.filter((model) => model.purpose === purpose);
}

const activeModels = computed(() => modelsForPurpose(activePurpose.value));

const deleteTarget = ref<AiModelListItemData | null>(null);
const isDeleting = ref(false);

function toggleModel(model: AiModelListItemData): void {
  router.put(
    AiModel.ToggleAiModelAction.url({ model: model.id }),
    {},
    {
      preserveScroll: true,
    },
  );
}

function move(
  models: AiModelListItemData[],
  index: number,
  direction: -1 | 1,
): void {
  const target = index + direction;
  if (target < 0 || target >= models.length) {
    return;
  }

  const orderedIds = models.map((model) => model.id);
  [orderedIds[index], orderedIds[target]] = [
    orderedIds[target],
    orderedIds[index],
  ];

  router.put(
    AiModel.ReorderAiModelsAction.url(),
    { purpose: activePurpose.value, ordered_ids: orderedIds },
    { preserveScroll: true },
  );
}

function confirmDelete(): void {
  if (!deleteTarget.value) {
    return;
  }

  isDeleting.value = true;
  router.delete(
    AiModel.DeleteAiModelAction.url({ model: deleteTarget.value.id }),
    {
      preserveScroll: true,
      onFinish: () => {
        isDeleting.value = false;
        deleteTarget.value = null;
      },
    },
  );
}
</script>

<template>
  <AppLayout>
    <Head :title="t('AI 模型管理')" />

    <SystemSettingsLayout content-class="max-w-none">
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('AI 模型管理')"
            :description="t('按用途管理模型，同用途内排序定主备。')"
          />
          <Button as-child>
            <Link :href="AiModel.ShowCreateAiModelPageAction.url()">
              {{ t('添加模型') }}
            </Link>
          </Button>
        </div>

        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="tab in props.purpose_tabs"
            :key="String(tab.value)"
            type="button"
            class="rounded-md border px-3 py-1.5 text-sm"
            :class="
              activePurpose === String(tab.value)
                ? 'border-foreground bg-foreground text-background'
                : 'text-muted-foreground hover:bg-muted'
            "
            @click="selectPurpose(String(tab.value))"
          >
            {{ tab.label }}
            <span class="ml-1 opacity-70">
              {{ modelsForPurpose(String(tab.value)).length }}
            </span>
          </button>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="px-4 py-3">{{ t('名称') }}</th>
                  <th class="px-4 py-3">{{ t('模型 ID') }}</th>
                  <th class="px-4 py-3">{{ t('供应商') }}</th>
                  <th class="px-4 py-3">{{ t('启用') }}</th>
                  <th class="w-56 px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(model, index) in activeModels"
                  :key="model.id"
                  class="border-t bg-background align-middle"
                >
                  <td class="px-4 py-3 font-medium">{{ model.name }}</td>
                  <td class="px-4 py-3 font-mono text-xs text-muted-foreground">
                    {{ model.model_id }}
                  </td>
                  <td class="px-4 py-3 text-muted-foreground">
                    {{ model.provider_name }}
                  </td>
                  <td class="px-4 py-3">
                    <Switch
                      :model-value="model.is_active"
                      :aria-label="model.is_active ? t('停用') : t('启用')"
                      @update:model-value="() => toggleModel(model)"
                    />
                  </td>
                  <td class="w-56 px-4 py-3">
                    <div class="flex items-center justify-end gap-1">
                      <Button
                        variant="ghost"
                        size="icon"
                        class="h-7 w-7"
                        :disabled="index === 0"
                        :aria-label="t('上移')"
                        @click="move(activeModels, index, -1)"
                      >
                        <ChevronUp class="h-4 w-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        class="h-7 w-7"
                        :disabled="index === activeModels.length - 1"
                        :aria-label="t('下移')"
                        @click="move(activeModels, index, 1)"
                      >
                        <ChevronDown class="h-4 w-4" />
                      </Button>
                      <Button as-child variant="outline" size="sm">
                        <Link
                          :href="
                            AiModel.ShowEditAiModelPageAction.url({
                              model: model.id,
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
                            :disabled="isDeleting"
                            @select="deleteTarget = model"
                          >
                            {{ t('删除') }}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>

                <tr v-if="activeModels.length === 0">
                  <td
                    colspan="5"
                    class="px-4 py-8 text-center text-muted-foreground"
                  >
                    {{ t('该用途暂无模型') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <ConfirmDeleteDialog
        :open="deleteTarget !== null"
        :title="t('确认删除模型？')"
        :detail-title="deleteTarget?.name"
        :detail-description="t('删除后该模型立即移出全局取用池，且无法恢复。')"
        :processing="isDeleting"
        @update:open="(value) => !value && (deleteTarget = null)"
        @confirm="confirmDelete"
      />
    </SystemSettingsLayout>
  </AppLayout>
</template>
