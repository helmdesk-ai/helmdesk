<!--
  文件说明：知识库分组的新建 / 编辑弹窗，承接重命名和改挂上级分组（最多 2 级）。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
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
import type { KnowledgeGroupData } from '@/types/generated';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
  open: boolean;
  mode: 'create' | 'edit';
  knowledgeBaseId: string;
  /** Top-level groups in the KB; used to render parent options. */
  groups: Array<KnowledgeGroupData>;
  /** Editing target (only used in edit mode). */
  group?: KnowledgeGroupData | null;
}>();

const emit = defineEmits<{
  'update:open': [value: boolean];
}>();

const { t } = useI18n();
const currentWorkspace = useRequiredWorkspace();

const NONE_VALUE = '__none__';

const selectedParentId = ref(NONE_VALUE);

const form = useForm({
  name: '',
  parent_id: '',
});

const isEditMode = computed(() => props.mode === 'edit');

const editingGroupHasChildren = computed(
  () =>
    isEditMode.value &&
    !!props.group?.children &&
    props.group.children.length > 0,
);

/**
 * 编辑顶级分组（含子分组）时，由于 2 级限制，不能再被挂到其它分组下；
 * 编辑分组时也要把自身从「上级分组」选项里去掉。
 */
const parentOptions = computed(() => {
  if (isEditMode.value && editingGroupHasChildren.value) {
    return [];
  }
  if (isEditMode.value && props.group) {
    return props.groups.filter(
      (g) => !g.is_default && g.id !== props.group?.id,
    );
  }
  return props.groups.filter((g) => !g.is_default);
});

const title = computed(() =>
  isEditMode.value ? t('编辑分组') : t('新建分组'),
);

const submitLabel = computed(() => (isEditMode.value ? t('保存') : t('创建')));

function initForm(): void {
  form.clearErrors();
  if (isEditMode.value && props.group) {
    form.name = props.group.name;
    selectedParentId.value = props.group.parent_id ?? NONE_VALUE;
    return;
  }

  form.name = '';
  selectedParentId.value = NONE_VALUE;
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      initForm();
    } else {
      form.reset();
      form.clearErrors();
    }
  },
);

function submit(): void {
  form.parent_id =
    selectedParentId.value === NONE_VALUE ? '' : selectedParentId.value;

  const onSuccess = () => emit('update:open', false);

  if (isEditMode.value && props.group) {
    form.put(
      KnowledgeBase.Group.UpdateKnowledgeGroupAction.url({
        slug: currentWorkspace.value.slug,
        knowledgeBase: props.knowledgeBaseId,
        group: props.group.id,
      }),
      { preserveScroll: true, onSuccess },
    );
    return;
  }

  form.post(
    KnowledgeBase.Group.CreateKnowledgeGroupAction.url({
      slug: currentWorkspace.value.slug,
      knowledgeBase: props.knowledgeBaseId,
    }),
    { preserveScroll: true, onSuccess },
  );
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-sm">
      <DialogHeader class="space-y-3">
        <DialogTitle>{{ title }}</DialogTitle>
        <DialogDescription>
          {{ t('分组最多支持两级，即分组下可再创建子分组。') }}
        </DialogDescription>
      </DialogHeader>

      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid gap-2">
          <Label for="group-name">{{ t('分组名称') }}</Label>
          <Input
            id="group-name"
            v-model="form.name"
            maxlength="120"
            :disabled="form.processing"
          />
          <p v-if="form.errors.name" class="text-xs text-destructive">
            {{ form.errors.name }}
          </p>
        </div>

        <div class="grid gap-2">
          <Label for="group-parent">{{ t('上级分组') }}</Label>
          <Select
            v-model="selectedParentId"
            :disabled="form.processing || editingGroupHasChildren"
          >
            <SelectTrigger id="group-parent" class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem :value="NONE_VALUE">
                {{ t('无（顶级分组）') }}
              </SelectItem>
              <SelectItem v-for="g in parentOptions" :key="g.id" :value="g.id">
                {{ g.name }}
              </SelectItem>
            </SelectContent>
          </Select>
          <p
            v-if="editingGroupHasChildren"
            class="text-xs text-muted-foreground"
          >
            {{ t('该分组下还有子分组，需先清空子分组才能移动到其它分组下。') }}
          </p>
          <p v-if="form.errors.parent_id" class="text-xs text-destructive">
            {{ form.errors.parent_id }}
          </p>
        </div>

        <DialogFooter class="gap-2">
          <Button
            type="button"
            variant="secondary"
            :disabled="form.processing"
            @click="emit('update:open', false)"
          >
            {{ t('取消') }}
          </Button>
          <Button type="submit" :disabled="form.processing">
            {{ submitLabel }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
