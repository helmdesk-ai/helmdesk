<!--
  文件说明：接待方案快照描述列表。
  - 用于版本详情与回收站只读详情，统一展示基础信息、接待智能体、任务智能体和服务场景
-->
<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/composables/useI18n';
import { simplifyModelLabel, valueOrDash } from '@/composables/useModelLabel';
import type {
  ModelCandidateData,
  ServiceScenarioData,
} from '@/types/generated';

export type PlanSnapshotDescriptionDetails = {
  plan_name: string | null;
  plan_description: string | null;
  persona_display_name: string;
  persona_tone_label: string;
  global_instructions: string | null;
  reception_model_label: string;
  reception_model_candidates: ModelCandidateData[];
  task_model_label: string;
  task_model_candidates: ModelCandidateData[];
  service_scenarios: ServiceScenarioData[];
};

const props = defineProps<{
  details: PlanSnapshotDescriptionDetails;
}>();

const { t } = useI18n();
</script>

<template>
  <div class="space-y-6">
    <section class="space-y-3">
      <h4 class="text-sm font-semibold">{{ t('基础信息') }}</h4>
      <dl class="divide-y rounded-md border text-sm">
        <div class="grid gap-1 px-3 py-2 sm:grid-cols-4 sm:gap-4">
          <dt class="text-muted-foreground">{{ t('方案名称') }}</dt>
          <dd class="sm:col-span-3">
            {{ valueOrDash(props.details.plan_name) }}
          </dd>
        </div>
        <div class="grid gap-1 px-3 py-2 sm:grid-cols-4 sm:gap-4">
          <dt class="text-muted-foreground">{{ t('方案简介') }}</dt>
          <dd class="whitespace-pre-wrap sm:col-span-3">
            {{ valueOrDash(props.details.plan_description) }}
          </dd>
        </div>
        <div class="grid gap-1 px-3 py-2 sm:grid-cols-4 sm:gap-4">
          <dt class="text-muted-foreground">{{ t('对外昵称') }}</dt>
          <dd class="sm:col-span-3">
            {{ valueOrDash(props.details.persona_display_name) }}
          </dd>
        </div>
      </dl>
    </section>

    <section class="space-y-3">
      <h4 class="text-sm font-semibold">{{ t('接待智能体') }}</h4>
      <dl class="divide-y rounded-md border text-sm">
        <div class="grid gap-1 px-3 py-2 sm:grid-cols-4 sm:gap-4">
          <dt class="text-muted-foreground">{{ t('语气风格') }}</dt>
          <dd class="sm:col-span-3">
            {{ props.details.persona_tone_label }}
          </dd>
        </div>
        <div class="grid gap-1 px-3 py-2 sm:grid-cols-4 sm:gap-4">
          <dt class="text-muted-foreground">{{ t('接待指引') }}</dt>
          <dd class="whitespace-pre-wrap sm:col-span-3">
            {{ valueOrDash(props.details.global_instructions) }}
          </dd>
        </div>
        <div class="grid gap-1 px-3 py-2 sm:grid-cols-4 sm:gap-4">
          <dt class="text-muted-foreground">{{ t('接待智能体模型') }}</dt>
          <dd class="sm:col-span-3">
            {{ valueOrDash(props.details.reception_model_label) }}
          </dd>
        </div>
        <div class="grid gap-1 px-3 py-2 sm:grid-cols-4 sm:gap-4">
          <dt class="text-muted-foreground">{{ t('备用模型') }}</dt>
          <dd class="space-y-1 sm:col-span-3">
            <div
              v-for="candidate in props.details.reception_model_candidates"
              :key="`${candidate.priority}-${candidate.ai_model_id}`"
              class="flex flex-wrap items-center gap-2"
            >
              <Badge variant="outline">
                {{ t('优先级 {priority}', { priority: candidate.priority }) }}
              </Badge>
              <span>
                {{
                  simplifyModelLabel(candidate.label) || candidate.ai_model_id
                }}
              </span>
            </div>
            <span
              v-if="props.details.reception_model_candidates.length === 0"
              class="text-muted-foreground"
            >
              —
            </span>
          </dd>
        </div>
      </dl>
    </section>

    <section class="space-y-3">
      <h4 class="text-sm font-semibold">{{ t('任务智能体') }}</h4>
      <dl class="divide-y rounded-md border text-sm">
        <div class="grid gap-1 px-3 py-2 sm:grid-cols-4 sm:gap-4">
          <dt class="text-muted-foreground">{{ t('任务智能体默认模型') }}</dt>
          <dd class="sm:col-span-3">
            {{ valueOrDash(props.details.task_model_label) }}
          </dd>
        </div>
        <div class="grid gap-1 px-3 py-2 sm:grid-cols-4 sm:gap-4">
          <dt class="text-muted-foreground">{{ t('备用模型') }}</dt>
          <dd class="space-y-1 sm:col-span-3">
            <div
              v-for="candidate in props.details.task_model_candidates"
              :key="`${candidate.priority}-${candidate.ai_model_id}`"
              class="flex flex-wrap items-center gap-2"
            >
              <Badge variant="outline">
                {{ t('优先级 {priority}', { priority: candidate.priority }) }}
              </Badge>
              <span>
                {{
                  simplifyModelLabel(candidate.label) || candidate.ai_model_id
                }}
              </span>
            </div>
            <span
              v-if="props.details.task_model_candidates.length === 0"
              class="text-muted-foreground"
            >
              —
            </span>
          </dd>
        </div>
      </dl>
    </section>

    <section class="space-y-3">
      <h4 class="text-sm font-semibold">
        {{ t('服务场景') }}
        <span class="ml-1 text-muted-foreground">
          ({{ props.details.service_scenarios.length }})
        </span>
      </h4>

      <div v-if="props.details.service_scenarios.length > 0" class="space-y-3">
        <article
          v-for="(scenario, index) in props.details.service_scenarios"
          :key="`${scenario.name}-${index}`"
          class="rounded-md border"
        >
          <div class="border-b px-3 py-2">
            <h5 class="text-sm font-medium">{{ scenario.name }}</h5>
            <p
              v-if="scenario.description"
              class="mt-1 text-xs text-muted-foreground"
            >
              {{ scenario.description }}
            </p>
          </div>
          <dl class="divide-y text-sm">
            <div class="grid gap-1 px-3 py-2 sm:grid-cols-4 sm:gap-4">
              <dt class="text-muted-foreground">{{ t('场景指令') }}</dt>
              <dd class="whitespace-pre-wrap sm:col-span-3">
                {{ valueOrDash(scenario.instructions) }}
              </dd>
            </div>
          </dl>
        </article>
      </div>

      <p
        v-else
        class="rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground"
      >
        {{ t('暂无服务场景') }}
      </p>
    </section>
  </div>
</template>
