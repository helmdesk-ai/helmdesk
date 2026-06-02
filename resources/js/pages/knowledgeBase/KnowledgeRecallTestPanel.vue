<!--
  知识库召回测试面板，内嵌在知识库列表右侧区域。
  管理员输入一段查询并选择检索模式（grep / 语义 / 混合），实时查看当前知识库的召回命中、
  来源、得分与诊断信息；走 useHttp 请求 RunKnowledgeRecallTestAction，不触发页面导航。
  消费后端 KnowledgeRecallTestResultData。
-->
<script setup lang="ts">
import KnowledgeBase from '@/actions/App/Actions/KnowledgeBase';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type {
  EnumOptionData,
  KnowledgeRecallTestResultData,
  KnowledgeSearchMode,
} from '@/types/generated';
import { useHttp } from '@inertiajs/vue3';
import { FileText, MessageSquareText, Search } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
  knowledgeBaseId: string;
  modeOptions: EnumOptionData[];
}>();

const emit = defineEmits<{
  cancel: [];
}>();

const { t } = useI18n();
const { toast } = useToast();

const defaultMode: KnowledgeSearchMode = props.modeOptions.some(
  (option) => option.value === 'semantic',
)
  ? 'semantic'
  : ((props.modeOptions[0]?.value ?? 'semantic') as KnowledgeSearchMode);

const http = useHttp<
  { query: string; mode: KnowledgeSearchMode },
  KnowledgeRecallTestResultData
>({
  query: '',
  mode: defaultMode,
});

const result = ref<KnowledgeRecallTestResultData | null>(null);
const hasSearched = ref(false);

const canSubmit = computed(
  () => http.query.trim().length > 0 && !http.processing,
);

const showSemanticSection = computed(
  () => http.mode === 'semantic' || http.mode === 'hybrid',
);
const showGrepSection = computed(
  () => http.mode === 'grep' || http.mode === 'hybrid',
);

/**
 * 诊断条上的 retriever 标记：仅语义/混合模式有意义。
 */
const retrieverChips = computed(() => {
  const diagnostics = result.value?.diagnostics;
  if (!diagnostics || !showSemanticSection.value) {
    return [];
  }
  return [
    { label: t('全文'), active: diagnostics.fulltext },
    { label: t('向量'), active: diagnostics.vector },
    { label: t('RAPTOR'), active: diagnostics.raptor },
    {
      label: diagnostics.rerank_applied ? t('已重排') : t('重排'),
      active: diagnostics.rerank_enabled,
    },
  ];
});

function runSearch(): void {
  const trimmed = http.query.trim();
  if (trimmed.length === 0 || http.processing) {
    return;
  }
  http.query = trimmed;
  hasSearched.value = true;

  http.post(
    KnowledgeBase.RunKnowledgeRecallTestAction.url({
      knowledgeBase: props.knowledgeBaseId,
    }),
    {
      onSuccess: (response: KnowledgeRecallTestResultData) => {
        result.value = response;
      },
      onHttpException: () => {
        toast.error(t('检索失败，请稍后再试'));
      },
      onNetworkError: () => {
        toast.error(t('网络异常，请检查连接'));
      },
    },
  );
}

/**
 * 文本域内 Cmd/Ctrl + Enter 直接发起检索。
 */
function onTextareaKeydown(event: KeyboardEvent): void {
  if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
    event.preventDefault();
    runSearch();
  }
}

function originIcon(originType: string) {
  return originType === 'qa' ? MessageSquareText : FileText;
}

function formatScore(score: number): string {
  return score.toFixed(3);
}
</script>

<template>
  <div class="mx-auto w-full max-w-none space-y-6">
    <div class="flex items-start justify-between gap-4">
      <HeadingSmall :title="t('检索测试')" />
      <Button
        type="button"
        variant="ghost"
        size="sm"
        class="shrink-0"
        @click="emit('cancel')"
      >
        {{ t('返回') }}
      </Button>
    </div>

    <!-- 查询表单 -->
    <form class="space-y-4" @submit.prevent="runSearch">
      <div class="grid gap-2">
        <Label for="recall-test-query" required>{{ t('查询内容') }}</Label>
        <Textarea
          id="recall-test-query"
          v-model="http.query"
          class="min-h-20 w-full"
          :aria-invalid="Boolean(http.errors.query)"
          @keydown="onTextareaKeydown"
        />
        <p v-if="http.errors.query" class="text-xs text-destructive">
          {{ http.errors.query }}
        </p>
      </div>

      <div class="flex flex-wrap items-end gap-3">
        <div class="grid w-full gap-2 sm:w-56">
          <Label for="recall-test-mode" required>{{ t('检索模式') }}</Label>
          <Select v-model="http.mode">
            <SelectTrigger id="recall-test-mode" class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in modeOptions"
                :key="String(option.value)"
                :value="String(option.value)"
              >
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <Button type="submit" :disabled="!canSubmit" class="shrink-0">
          <Spinner v-if="http.processing" class="mr-1.5 h-4 w-4" />
          <Search v-else class="mr-1.5 h-4 w-4" />
          {{ t('检索') }}
        </Button>
      </div>
    </form>

    <!-- 初始空态 -->
    <div
      v-if="!hasSearched"
      class="flex flex-col items-center justify-center rounded-lg border border-dashed py-16 text-center"
    >
      <Search class="h-10 w-10 text-muted-foreground/40" />
    </div>

    <!-- 检索中骨架 -->
    <div v-else-if="http.processing && !result" class="space-y-3">
      <div class="h-20 animate-pulse rounded-lg bg-muted/50" />
      <div class="h-20 animate-pulse rounded-lg bg-muted/50" />
      <div class="h-20 animate-pulse rounded-lg bg-muted/50" />
    </div>

    <!-- 结果 -->
    <div v-else-if="result" class="space-y-6">
      <!-- 诊断条 -->
      <div
        class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border bg-muted/20 px-3 py-2.5 text-xs"
      >
        <span class="text-muted-foreground">
          {{
            t('共命中 {count} 条', {
              count:
                result.diagnostics.semantic_count +
                result.diagnostics.grep_count,
            })
          }}
        </span>
        <template v-if="retrieverChips.length > 0">
          <span class="h-3.5 w-px bg-border" aria-hidden="true" />
          <div class="flex flex-wrap items-center gap-1.5">
            <span class="text-muted-foreground">{{ t('检索路径') }}</span>
            <Badge
              v-for="chip in retrieverChips"
              :key="chip.label"
              :variant="chip.active ? 'secondary' : 'outline'"
              :class="chip.active ? '' : 'text-muted-foreground/50'"
            >
              {{ chip.label }}
            </Badge>
          </div>
        </template>
        <Badge v-if="result.diagnostics.embedding_failed" variant="destructive">
          {{ t('嵌入失败，已回退全文') }}
        </Badge>
      </div>

      <!-- 语义结果 -->
      <section v-if="showSemanticSection" class="space-y-3">
        <h4 class="text-sm font-medium">
          {{ t('语义命中') }}
          <span class="font-normal text-muted-foreground">
            （{{ result.semantic_hits.length }}）
          </span>
        </h4>
        <p
          v-if="result.semantic_hits.length === 0"
          class="rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
        >
          {{ t('未命中任何内容') }}
        </p>
        <ul v-else class="space-y-2">
          <li
            v-for="(hit, index) in result.semantic_hits"
            :key="`semantic:${index}`"
            class="rounded-lg border p-3"
          >
            <div class="mb-1.5 flex flex-wrap items-center gap-2">
              <span class="text-xs font-medium text-muted-foreground">
                #{{ hit.rank }}
              </span>
              <Badge variant="secondary">{{ hit.source_label }}</Badge>
              <span
                class="inline-flex min-w-0 items-center gap-1 text-xs text-muted-foreground"
              >
                <component
                  :is="originIcon(hit.origin_type)"
                  class="h-3.5 w-3.5 shrink-0"
                />
                <span class="truncate">
                  {{ hit.origin_title ?? t('未知来源') }}
                </span>
              </span>
              <span class="ml-auto font-mono text-xs text-muted-foreground">
                {{ t('得分') }} {{ formatScore(hit.score) }}
              </span>
            </div>
            <p
              v-if="hit.heading_path"
              class="mb-1 truncate text-xs text-muted-foreground"
            >
              {{ hit.heading_path }}
            </p>
            <p
              class="line-clamp-4 text-sm whitespace-pre-wrap text-foreground/90"
            >
              {{ hit.content }}
            </p>
          </li>
        </ul>
      </section>

      <!-- grep 结果 -->
      <section v-if="showGrepSection" class="space-y-3">
        <h4 class="text-sm font-medium">
          {{ t('字面命中') }}
          <span class="font-normal text-muted-foreground">
            （{{ result.grep_matches.length }}）
          </span>
        </h4>
        <p
          v-if="result.grep_matches.length === 0"
          class="rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
        >
          {{ t('未命中任何内容') }}
        </p>
        <ul v-else class="space-y-2">
          <li
            v-for="(match, index) in result.grep_matches"
            :key="`grep:${index}`"
            class="rounded-lg border p-3"
          >
            <div class="mb-1.5 flex flex-wrap items-center gap-2">
              <Badge variant="outline">{{ match.field_label }}</Badge>
              <span
                class="inline-flex min-w-0 items-center gap-1 text-xs text-muted-foreground"
              >
                <component
                  :is="originIcon(match.origin_type)"
                  class="h-3.5 w-3.5 shrink-0"
                />
                <span class="truncate">
                  {{ match.origin_title ?? t('未知来源') }}
                </span>
              </span>
              <span class="ml-auto font-mono text-xs text-muted-foreground">
                {{ t('第 {line} 行', { line: match.line }) }}
              </span>
            </div>
            <p class="text-sm break-words text-foreground/90">
              <span class="text-muted-foreground">{{
                match.context_before
              }}</span>
              <span
                class="rounded-sm bg-foreground px-0.5 font-medium text-background"
              >
                {{ match.match }}
              </span>
              <span class="text-muted-foreground">{{
                match.context_after
              }}</span>
            </p>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>
