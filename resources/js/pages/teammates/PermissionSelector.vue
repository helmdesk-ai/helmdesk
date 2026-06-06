<!--
  文件说明：客服权限选择器，以弹窗形式按业务模块分组勾选权限。
  供客服创建/编辑页面复用，消费后端 PermissionGroupData。
-->
<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';
import type { PermissionGroupData } from '@/types/generated';
import { computed, ref } from 'vue';

const props = defineProps<{
  groups: PermissionGroupData[];
  modelValue: string[];
  disabled?: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: string[]];
}>();

const { t } = useI18n();

// 弹窗内的草稿选择，确认后才回写父级表单，取消则丢弃
const open = ref(false);
const draft = ref<string[]>([]);

const allValues = computed<string[]>(() =>
  props.groups.flatMap((group) =>
    group.permissions.map((permission) => String(permission.value)),
  ),
);

function openDialog(): void {
  if (props.disabled) {
    return;
  }
  draft.value = [...props.modelValue];
  open.value = true;
}

function isChecked(value: string | number): boolean {
  return draft.value.includes(String(value));
}

function toggle(value: string | number, checked: boolean): void {
  const normalized = String(value);
  if (checked) {
    if (!draft.value.includes(normalized)) {
      draft.value = [...draft.value, normalized];
    }
    return;
  }
  draft.value = draft.value.filter((permission) => permission !== normalized);
}

function selectAll(): void {
  draft.value = [...allValues.value];
}

function clearAll(): void {
  draft.value = [];
}

function confirm(): void {
  emit('update:modelValue', [...draft.value]);
  open.value = false;
}
</script>

<template>
  <div>
    <Button
      type="button"
      variant="outline"
      :disabled="props.disabled"
      @click="openDialog"
    >
      {{ t('配置权限') }}
      <Badge
        v-if="props.modelValue.length > 0"
        variant="secondary"
        class="ml-1.5"
      >
        {{ t('已选 {count} 项', { count: props.modelValue.length }) }}
      </Badge>
      <span v-else class="ml-1.5 text-muted-foreground">
        {{ t('未分配') }}
      </span>
    </Button>

    <Dialog :open="open" @update:open="open = $event">
      <DialogContent class="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{{ t('配置权限') }}</DialogTitle>
          <DialogDescription>
            {{ t('按业务模块勾选该客服可执行的操作。') }}
          </DialogDescription>
        </DialogHeader>

        <div class="flex items-center justify-between border-b pb-2">
          <span class="text-sm text-muted-foreground">
            {{ t('已选 {count} 项', { count: draft.length }) }}
          </span>
          <div class="flex gap-1">
            <Button type="button" variant="ghost" size="sm" @click="selectAll">
              {{ t('全选') }}
            </Button>
            <Button type="button" variant="ghost" size="sm" @click="clearAll">
              {{ t('清空') }}
            </Button>
          </div>
        </div>

        <div class="max-h-[60vh] space-y-5 overflow-y-auto pr-1">
          <div v-for="group in props.groups" :key="group.key" class="space-y-3">
            <div class="text-sm font-medium">{{ group.label }}</div>
            <div class="grid gap-2 sm:grid-cols-2">
              <label
                v-for="permission in group.permissions"
                :key="String(permission.value)"
                class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm"
              >
                <Checkbox
                  :model-value="isChecked(permission.value)"
                  @update:model-value="
                    (checked) => toggle(permission.value, checked === true)
                  "
                />
                <span>{{ permission.label }}</span>
              </label>
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" @click="open = false">
            {{ t('取消') }}
          </Button>
          <Button type="button" @click="confirm">
            {{ t('确定') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
