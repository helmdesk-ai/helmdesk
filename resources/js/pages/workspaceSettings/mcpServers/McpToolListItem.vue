<!--
  文件说明：MCP 工具单行展示组件，含展开 schema 和下线徽章。
-->
<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import type { McpToolData } from '@/types/generated';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
  tool: McpToolData;
}>();

const { t } = useI18n();
const isExpanded = ref(false);

const isRemoved = computed(() => props.tool.removed_at !== null);

const description = computed(
  () => props.tool.description ?? t('远端未提供描述'),
);

const schemaText = computed(() => {
  if (!props.tool.input_schema) {
    return null;
  }
  return JSON.stringify(props.tool.input_schema, null, 2);
});

const annotationsText = computed(() => {
  if (!props.tool.annotations) {
    return null;
  }
  return JSON.stringify(props.tool.annotations, null, 2);
});
</script>

<template>
  <div class="rounded-lg border">
    <div class="flex items-start justify-between gap-2 px-4 py-3">
      <button
        type="button"
        class="flex min-w-0 flex-1 items-start gap-2 text-left"
        @click="isExpanded = !isExpanded"
      >
        <ChevronDown
          v-if="isExpanded"
          class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground"
        />
        <ChevronRight
          v-else
          class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground"
        />
        <div class="min-w-0 flex-1 space-y-1">
          <div class="flex items-center gap-2">
            <span class="font-mono text-sm font-medium">{{ tool.name }}</span>
            <Badge v-if="isRemoved" variant="destructive" class="text-[10px]">
              {{ t('已下线') }}
            </Badge>
          </div>
          <p class="line-clamp-2 text-xs text-muted-foreground">
            {{ description }}
          </p>
        </div>
      </button>
    </div>

    <div v-if="isExpanded" class="space-y-3 border-t px-4 py-3">
      <div v-if="schemaText">
        <Label class="text-xs font-semibold text-muted-foreground">
          {{ t('Input Schema') }}
        </Label>
        <pre
          class="mt-1 max-h-60 overflow-auto rounded bg-muted p-2 font-mono text-[11px] leading-relaxed"
          >{{ schemaText }}</pre
        >
      </div>

      <div v-if="annotationsText">
        <Label class="text-xs font-semibold text-muted-foreground">
          {{ t('工具标注') }}
        </Label>
        <pre
          class="mt-1 max-h-40 overflow-auto rounded bg-muted p-2 font-mono text-[11px] leading-relaxed"
          >{{ annotationsText }}</pre
        >
      </div>
    </div>
  </div>
</template>
