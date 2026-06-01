<!--
  文件说明：工作区数据设置页面，承接自定义属性的列表和配置表单。
-->
<script setup lang="ts">
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import InputError from '@/components/common/InputError.vue';
import OptionListEditor from '@/components/custom-attribute/OptionListEditor.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import AppLayout from '@/layouts/AppLayout.vue';
import WorkspaceSettingsLayout from '@/layouts/WorkspaceSettingsLayout.vue';
import workspace from '@/routes/workspace';
import type {
  FormCreateAttributeDefinitionData,
  FormUpdateAttributeDefinitionData,
  ListAttributeDefinitionItemData,
  ShowListAttributeDefinitionPagePropsData,
} from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, MoreHorizontal } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const { t } = useI18n();
const props = defineProps<ShowListAttributeDefinitionPagePropsData>();
const currentWorkspace = useRequiredWorkspace();

const SELECT_TYPES = ['single_select', 'multi_select'];
const FILTERABLE_TYPES = ['single_select', 'boolean', 'date', 'number'];

const createOpen = ref(false);
const editOpen = ref(false);
const archiveTarget = ref<ListAttributeDefinitionItemData | null>(null);
const editingDef = ref<ListAttributeDefinitionItemData | null>(null);
const keyManuallyEdited = ref(false);

const createForm = useForm<FormCreateAttributeDefinitionData>({
  key: '',
  name: '',
  description: null,
  type: '',
  config: null,
  is_filterable: false,
});

const editForm = useForm<FormUpdateAttributeDefinitionData>({
  name: '',
  description: null,
  config: null,
  is_filterable: false,
});

const archiveForm = useForm({});
const reorderForm = useForm<{ ordered_ids: string[] }>({
  ordered_ids: [],
});

const createOptions = ref<Array<{ code: string; label: string }>>([
  { code: '', label: '' },
]);
const editOptions = ref<Array<{ code: string; label: string }>>([]);

const createDescription = computed<string>({
  get: () => createForm.description ?? '',
  set: (v) => {
    createForm.description = v === '' ? null : v;
  },
});

const editDescription = computed<string>({
  get: () => editForm.description ?? '',
  set: (v) => {
    editForm.description = v === '' ? null : v;
  },
});

const isCreateSelectType = computed(() =>
  SELECT_TYPES.includes(createForm.type),
);
const createTypeSupportsFiltering = computed(() =>
  FILTERABLE_TYPES.includes(createForm.type),
);
const isEditSelectType = computed(
  () => !!editingDef.value && SELECT_TYPES.includes(editingDef.value.type),
);
const editTypeSupportsFiltering = computed(
  () => !!editingDef.value && FILTERABLE_TYPES.includes(editingDef.value.type),
);

const slugify = (name: string): string => {
  return name
    .toLowerCase()
    .replace(/[\u4e00-\u9fff]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .substring(0, 50);
};

watch(
  () => createForm.name,
  (name) => {
    if (!keyManuallyEdited.value) {
      createForm.key = slugify(name);
    }
  },
);

watch(
  () => createForm.type,
  (newType) => {
    if (!FILTERABLE_TYPES.includes(newType)) {
      createForm.is_filterable = false;
    }

    if (SELECT_TYPES.includes(newType)) {
      createForm.config = { options: createOptions.value };
    } else {
      createForm.config = null;
    }
  },
);

watch(
  createOptions,
  (opts) => {
    if (isCreateSelectType.value) {
      createForm.config = { options: opts };
    }
  },
  { deep: true },
);

watch(
  editOptions,
  (opts) => {
    if (isEditSelectType.value) {
      editForm.config = { options: opts };
    }
  },
  { deep: true },
);

watch(createOpen, (open) => {
  if (open || createForm.processing) {
    return;
  }

  createForm.reset();
  createForm.is_filterable = false;
  createOptions.value = [{ code: '', label: '' }];
  keyManuallyEdited.value = false;
  createForm.clearErrors();
});

watch(editOpen, (open) => {
  if (open || editForm.processing) {
    return;
  }

  editingDef.value = null;
  editOptions.value = [];
  editForm.reset();
  editForm.is_filterable = false;
  editForm.clearErrors();
});

const openCreate = () => {
  createForm.reset();
  createForm.clearErrors();
  keyManuallyEdited.value = false;
  createOptions.value = [{ code: '', label: '' }];
  createOpen.value = true;
};

const submitCreate = () => {
  createForm.post(
    workspace.manage.attributes.store.url(currentWorkspace.value.slug),
    {
      preserveScroll: true,
      onSuccess: () => {
        createOpen.value = false;
        createForm.reset();
      },
    },
  );
};

const openEdit = (def: ListAttributeDefinitionItemData) => {
  editingDef.value = def;
  editForm.name = def.name;
  editForm.description = def.description ?? null;
  editForm.is_filterable = FILTERABLE_TYPES.includes(def.type)
    ? def.is_filterable
    : false;
  editForm.clearErrors();

  if (SELECT_TYPES.includes(def.type) && def.config?.options) {
    editOptions.value = [...def.config.options];
    editForm.config = { options: editOptions.value };
  } else {
    editOptions.value = [];
    editForm.config = def.config;
  }

  editOpen.value = true;
};

const submitEdit = () => {
  if (!editingDef.value) {
    return;
  }

  editForm.put(
    workspace.manage.attributes.update.url({
      slug: currentWorkspace.value.slug,
      id: editingDef.value.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        editOpen.value = false;
        editingDef.value = null;
      },
    },
  );
};

const openArchiveDialog = (def: ListAttributeDefinitionItemData) => {
  archiveTarget.value = def;
};

const closeArchiveDialog = (open: boolean) => {
  if (open || archiveForm.processing) {
    return;
  }
  archiveTarget.value = null;
};

const submitArchive = () => {
  if (!archiveTarget.value) {
    return;
  }

  archiveForm.put(
    workspace.manage.attributes.archive.url({
      slug: currentWorkspace.value.slug,
      id: archiveTarget.value.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        archiveTarget.value = null;
      },
    },
  );
};

const moveDefinition = (definitionId: string, direction: 'up' | 'down') => {
  const orderedDefinitions = [...props.definition_list];
  const currentIndex = orderedDefinitions.findIndex(
    (definition) => definition.id === definitionId,
  );

  if (currentIndex === -1) {
    return;
  }

  const targetIndex = direction === 'up' ? currentIndex - 1 : currentIndex + 1;

  if (targetIndex < 0 || targetIndex >= orderedDefinitions.length) {
    return;
  }

  const [movedDefinition] = orderedDefinitions.splice(currentIndex, 1);
  orderedDefinitions.splice(targetIndex, 0, movedDefinition);

  reorderForm.ordered_ids = orderedDefinitions.map(
    (definition) => definition.id,
  );
  reorderForm.put(
    workspace.manage.attributes.reorder.url(currentWorkspace.value.slug),
    {
      preserveScroll: true,
    },
  );
};
</script>

<template>
  <AppLayout>
    <Head :title="t('自定义属性')" />

    <WorkspaceSettingsLayout>
      <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('自定义属性')"
            :description="t('为联系人和会话扩展自定义字段，记录业务所需信息。')"
          />

          <div class="flex items-center gap-2">
            <Dialog v-model:open="createOpen">
              <DialogTrigger as-child>
                <Button @click="openCreate">
                  {{ t('新增属性') }}
                </Button>
              </DialogTrigger>
              <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader class="space-y-3">
                  <DialogTitle>{{ t('新增属性') }}</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submitCreate">
                  <div class="space-y-2">
                    <Label for="create-name">{{ t('属性名称') }}</Label>
                    <Input
                      id="create-name"
                      v-model="createForm.name"
                      :disabled="createForm.processing"
                    />
                    <InputError :message="createForm.errors.name" />
                  </div>

                  <div class="space-y-2">
                    <Label for="create-key">{{ t('属性标识') }}</Label>
                    <Input
                      id="create-key"
                      v-model="createForm.key"
                      :disabled="createForm.processing"
                      class="font-mono"
                      @input="keyManuallyEdited = true"
                    />
                    <p class="text-xs text-muted-foreground">
                      {{ t('属性标识创建后不可修改') }}
                    </p>
                    <InputError :message="createForm.errors.key" />
                  </div>

                  <div class="space-y-2">
                    <Label for="create-type">{{ t('属性类型') }}</Label>
                    <Select
                      v-model="createForm.type"
                      :disabled="createForm.processing"
                    >
                      <SelectTrigger id="create-type">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem
                          v-for="opt in props.type_options"
                          :key="String(opt.value)"
                          :value="String(opt.value)"
                        >
                          {{ opt.label }}
                        </SelectItem>
                      </SelectContent>
                    </Select>
                    <InputError :message="createForm.errors.type" />
                  </div>

                  <div class="space-y-2">
                    <Label for="create-desc">{{ t('属性描述') }}</Label>
                    <Input
                      id="create-desc"
                      v-model="createDescription"
                      :disabled="createForm.processing"
                    />
                    <InputError :message="createForm.errors.description" />
                  </div>

                  <div v-if="isCreateSelectType">
                    <OptionListEditor
                      v-model="createOptions"
                      :disabled="createForm.processing"
                      :errors="createForm.errors.config"
                    />
                  </div>

                  <div
                    v-if="createTypeSupportsFiltering"
                    class="flex items-center gap-2"
                  >
                    <Checkbox
                      id="create-filterable"
                      v-model="createForm.is_filterable"
                      :disabled="createForm.processing"
                    />
                    <Label for="create-filterable" class="cursor-pointer">
                      {{ t('可筛选') }}
                    </Label>
                  </div>
                  <InputError :message="createForm.errors.is_filterable" />

                  <DialogFooter class="gap-2">
                    <DialogClose as-child>
                      <Button
                        type="button"
                        variant="secondary"
                        :disabled="createForm.processing"
                      >
                        {{ t('取消') }}
                      </Button>
                    </DialogClose>
                    <Button type="submit" :disabled="createForm.processing">
                      {{ t('保存') }}
                    </Button>
                  </DialogFooter>
                </form>
              </DialogContent>
            </Dialog>

            <Button variant="outline" as-child>
              <Link
                :href="
                  workspace.manage.attributes.trash.url(currentWorkspace.slug)
                "
              >
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
                  <th class="px-4 py-3">{{ t('属性名称') }}</th>
                  <th class="px-4 py-3">{{ t('属性标识') }}</th>
                  <th class="px-4 py-3">{{ t('属性类型') }}</th>
                  <th class="px-4 py-3">{{ t('可筛选') }}</th>
                  <th class="px-4 py-3">{{ t('使用数') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(def, index) in props.definition_list"
                  :key="def.id"
                  class="border-t bg-background"
                >
                  <td class="px-4 py-3 font-medium">
                    {{ def.name }}
                  </td>
                  <td class="px-4 py-3">
                    <code class="rounded bg-muted px-1.5 py-0.5 text-xs">
                      {{ def.key }}
                    </code>
                  </td>
                  <td class="px-4 py-3">
                    {{ def.type_label }}
                  </td>
                  <td class="px-4 py-3">
                    <Badge v-if="def.is_filterable" variant="secondary">
                      {{ t('可筛选') }}
                    </Badge>
                    <span v-else class="text-muted-foreground">-</span>
                  </td>
                  <td class="px-4 py-3">
                    <span class="text-muted-foreground">
                      {{ def.usage_count }}
                    </span>
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex justify-end gap-2">
                      <div class="flex gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          class="h-8 w-8"
                          :disabled="reorderForm.processing || index === 0"
                          @click="moveDefinition(def.id, 'up')"
                        >
                          <ArrowUp class="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          class="h-8 w-8"
                          :disabled="
                            reorderForm.processing ||
                            index === props.definition_list.length - 1
                          "
                          @click="moveDefinition(def.id, 'down')"
                        >
                          <ArrowDown class="h-4 w-4" />
                        </Button>
                      </div>
                      <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                          editForm.processing ||
                          archiveForm.processing ||
                          reorderForm.processing
                        "
                        @click="openEdit(def)"
                      >
                        {{ t('编辑') }}
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
                              archiveForm.processing || reorderForm.processing
                            "
                            @select="openArchiveDialog(def)"
                          >
                            {{ t('删除') }}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>

                <tr v-if="props.definition_list.length === 0">
                  <td
                    class="px-4 py-8 text-center text-muted-foreground"
                    colspan="6"
                  >
                    {{ t('暂无自定义属性') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <Dialog v-model:open="editOpen">
          <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader class="space-y-3">
              <DialogTitle>{{ t('编辑属性') }}</DialogTitle>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submitEdit">
              <div class="space-y-2">
                <Label>{{ t('属性标识') }}</Label>
                <Input
                  :model-value="editingDef?.key"
                  disabled
                  class="font-mono"
                />
                <p class="text-xs text-muted-foreground">
                  {{ t('属性标识创建后不可修改') }}
                </p>
              </div>

              <div class="space-y-2">
                <Label>{{ t('属性类型') }}</Label>
                <Input :model-value="editingDef?.type_label" disabled />
                <p class="text-xs text-muted-foreground">
                  {{ t('属性类型创建后不可修改') }}
                </p>
              </div>

              <div class="space-y-2">
                <Label for="edit-name">{{ t('属性名称') }}</Label>
                <Input
                  id="edit-name"
                  v-model="editForm.name"
                  :disabled="editForm.processing"
                />
                <InputError :message="editForm.errors.name" />
              </div>

              <div class="space-y-2">
                <Label for="edit-desc">{{ t('属性描述') }}</Label>
                <Input
                  id="edit-desc"
                  v-model="editDescription"
                  :disabled="editForm.processing"
                />
                <InputError :message="editForm.errors.description" />
              </div>

              <div v-if="isEditSelectType">
                <OptionListEditor
                  v-model="editOptions"
                  :disabled="editForm.processing"
                  :errors="editForm.errors.config"
                />
              </div>

              <div
                v-if="editTypeSupportsFiltering"
                class="flex items-center gap-2"
              >
                <Checkbox
                  id="edit-filterable"
                  v-model="editForm.is_filterable"
                  :disabled="editForm.processing"
                />
                <Label for="edit-filterable" class="cursor-pointer">
                  {{ t('可筛选') }}
                </Label>
              </div>
              <InputError :message="editForm.errors.is_filterable" />

              <DialogFooter class="gap-2">
                <DialogClose as-child>
                  <Button
                    type="button"
                    variant="secondary"
                    :disabled="editForm.processing"
                  >
                    {{ t('取消') }}
                  </Button>
                </DialogClose>
                <Button type="submit" :disabled="editForm.processing">
                  {{ t('保存') }}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </WorkspaceSettingsLayout>

    <ConfirmDeleteDialog
      :open="archiveTarget !== null"
      :title="t('确认删除属性？')"
      :detail-title="archiveTarget?.name"
      :detail-description="
        t('删除后可在已删除属性中恢复，已有联系人数据会保留。')
      "
      :processing="archiveForm.processing"
      @update:open="closeArchiveDialog"
      @confirm="submitArchive"
    />
  </AppLayout>
</template>
