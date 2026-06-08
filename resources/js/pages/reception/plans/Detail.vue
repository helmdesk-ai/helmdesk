<!--
  文件说明：接待方案详情（编辑）页，承接选中方案的完整配置编辑，点击保存后生效。
  按业务分段标签页组织：基础信息 / 流程策略 / 营业时间 / 自动回复 / 接待智能体 / 任务智能体 /
  服务场景 / 关联知识库 / MCP 工具；服务场景与 KB/MCP 关联通过 Dialog 编辑。
  消费后端 ShowReceptionPlanDetailPagePropsData。
-->
<script setup lang="ts">
import Plan from '@/actions/App/Actions/Reception/Plan';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import FormActions from '@/components/common/FormActions.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import PlanBasicsForm, {
  type MessageTranslationConfigDraft,
  type PlanBasicsFormSection,
  type PlanBasicsFormShape,
  type ReceptionModelCandidateDraft,
} from '@/pages/reception/plans/PlanBasicsForm.vue';
import PlanBusinessHoursForm from '@/pages/reception/plans/PlanBusinessHoursForm.vue';
import PlanStrategyForm, {
  type ReceptionStrategyConfigDraft,
} from '@/pages/reception/plans/PlanStrategyForm.vue';
import ServiceScenarioFormPanel, {
  type ServiceScenarioDraft,
} from '@/pages/reception/plans/service-scenarios/ServiceScenarioFormDialog.vue';
import type {
  ServiceScenarioTemplateData,
  ShowReceptionPlanDetailPagePropsData,
} from '@/types/generated';
import { Head, useForm } from '@inertiajs/vue3';
import {
  BookOpen,
  Check,
  ChevronDown,
  HelpCircle,
  Pencil,
  Smartphone,
  Trash2,
  X,
} from '@lucide/vue';
import { computed, ref, watch, type Component } from 'vue';

type PlanFormTab =
  | PlanBasicsFormSection
  | 'strategy'
  | 'business_hours'
  | 'auto_messages'
  | 'service_scenarios'
  | 'knowledge_bases'
  | 'mcp_tools';
type AutoMessageTrigger =
  | 'ai_welcome'
  | 'teammate_joined'
  | 'teammate_transferred';

type AutoMessageDraft = {
  enabled: boolean;
  message: string;
};

type ServiceScenarioFormState =
  | { mode: 'closed' }
  | { mode: 'create'; template: ServiceScenarioTemplateData | null }
  | { mode: 'edit'; index: number; draft: ServiceScenarioDraft };

const props = defineProps<ShowReceptionPlanDetailPagePropsData>();
const { t } = useI18n();
const displayNameVariable = '{{display_name}}';
const teammateNameVariable = '{{teammate_name}}';

const planFormTabs: Array<{ value: PlanFormTab; label: string }> = [
  { value: 'basic', label: '基础信息' },
  { value: 'strategy', label: '流程策略' },
  { value: 'business_hours', label: '营业时间' },
  { value: 'auto_messages', label: '自动回复' },
  { value: 'reception', label: '接待智能体' },
  { value: 'task', label: '任务智能体' },
  { value: 'service_scenarios', label: '服务场景' },
  { value: 'knowledge_bases', label: '关联知识库' },
  { value: 'mcp_tools', label: 'MCP 工具' },
];

const tabQueryParam = 'tab';

const readPlanFormTabFromUrl = (): PlanFormTab => {
  if (typeof window === 'undefined') {
    return 'basic';
  }

  const url = new URL(window.location.href);
  const requested = url.searchParams.get(tabQueryParam);
  return planFormTabs.some((tab) => tab.value === requested)
    ? (requested as PlanFormTab)
    : 'basic';
};

const writeTabToUrl = (tab: PlanFormTab): void => {
  if (typeof window === 'undefined') {
    return;
  }

  const url = new URL(window.location.href);
  if (tab === 'basic') {
    url.searchParams.delete(tabQueryParam);
  } else {
    url.searchParams.set(tabQueryParam, tab);
  }
  window.history.replaceState(window.history.state, '', url.toString());
};

const activePlanFormTab = ref<PlanFormTab>(readPlanFormTabFromUrl());
watch(activePlanFormTab, (tab) => writeTabToUrl(tab));

const activePlanBasicsSection = computed<PlanBasicsFormSection>(() =>
  activePlanFormTab.value === 'service_scenarios' ||
  activePlanFormTab.value === 'knowledge_bases' ||
  activePlanFormTab.value === 'mcp_tools' ||
  activePlanFormTab.value === 'auto_messages' ||
  activePlanFormTab.value === 'business_hours' ||
  activePlanFormTab.value === 'strategy'
    ? 'basic'
    : activePlanFormTab.value,
);

const listUrl = computed(() => Plan.ShowReceptionPlanIndexPageAction.url());

// ---------- 方案表单：基础字段 + 服务场景 + 方案级 KB/MCP 统一存放在 useForm 里 ----------
type PlanFormState = PlanBasicsFormShape & {
  service_scenarios: ServiceScenarioDraft[];
  knowledge_base_ids: string[];
  mcp_tool_ids: string[];
  strategy_config: ReceptionStrategyConfigDraft;
  auto_messages_config: Record<AutoMessageTrigger, AutoMessageDraft>;
};

function planMessageTranslationConfig(): MessageTranslationConfigDraft {
  const plan = props.plan;
  return {
    enabled: plan.translation_config.enabled,
    failure_mode: plan.translation_config.failure_mode,
  };
}

function planAutoMessagesConfig(): Record<
  AutoMessageTrigger,
  AutoMessageDraft
> {
  const plan = props.plan;
  return {
    ai_welcome: {
      enabled: plan.auto_messages_config.ai_welcome.enabled,
      message: plan.auto_messages_config.ai_welcome.message ?? '',
    },
    teammate_joined: {
      enabled: plan.auto_messages_config.teammate_joined.enabled,
      message: plan.auto_messages_config.teammate_joined.message ?? '',
    },
    teammate_transferred: {
      enabled: plan.auto_messages_config.teammate_transferred.enabled,
      message: plan.auto_messages_config.teammate_transferred.message ?? '',
    },
  };
}

function planStrategyConfig(): ReceptionStrategyConfigDraft {
  const plan = props.plan;
  return {
    reception_mode: plan.strategy_config.reception_mode,
    unassigned_ai_takeover_enabled:
      plan.strategy_config.unassigned_ai_takeover_enabled,
    unassigned_ai_takeover_timeout_seconds:
      plan.strategy_config.unassigned_ai_takeover_timeout_seconds,
    teammate_no_response_ai_takeover_enabled:
      plan.strategy_config.teammate_no_response_ai_takeover_enabled,
    teammate_no_response_ai_takeover_timeout_seconds:
      plan.strategy_config.teammate_no_response_ai_takeover_timeout_seconds,
    important_contact_ai_careful_reply_enabled:
      plan.strategy_config.important_contact_ai_careful_reply_enabled,
    important_contact_ai_handoff_hint_enabled:
      plan.strategy_config.important_contact_ai_handoff_hint_enabled,
    important_contact_human_first_when_online_enabled:
      plan.strategy_config.important_contact_human_first_when_online_enabled,
    quote_visitor_message_enabled:
      plan.strategy_config.quote_visitor_message_enabled,
    handoff_available_notice: plan.strategy_config.handoff_available_notice,
    handoff_no_teammate_notice: plan.strategy_config.handoff_no_teammate_notice,
    ai_unavailable_notice: plan.strategy_config.ai_unavailable_notice,
    business_hours: plan.strategy_config.business_hours
      ? {
          timezone: plan.strategy_config.business_hours.timezone,
          outside_hours_notice:
            plan.strategy_config.business_hours.outside_hours_notice,
          schedule: plan.strategy_config.business_hours.schedule.map((day) => ({
            day: day.day,
            enabled: day.enabled,
            open: day.open,
            close: day.close,
          })),
        }
      : null,
  };
}

function planFormDefaults(): PlanFormState {
  const plan = props.plan;
  return {
    name: plan.name,
    description: plan.description ?? '',
    persona_display_name: plan.persona_config.display_name,
    persona_tone: plan.persona_config.tone,
    global_instructions: plan.global_instructions ?? '',
    reception_ai_model_id: plan.reception_model?.ai_model_id ?? '',
    reception_model_candidates: plan.reception_model_candidates
      .filter((candidate) => candidate.priority > 0)
      .map(
        (candidate, index): ReceptionModelCandidateDraft => ({
          ai_model_id: candidate.ai_model_id,
          priority: index + 1,
        }),
      ),
    task_ai_model_id: plan.task_model?.ai_model_id ?? '',
    task_model_candidates: plan.task_model_candidates
      .filter((candidate) => candidate.priority > 0)
      .map(
        (candidate, index): ReceptionModelCandidateDraft => ({
          ai_model_id: candidate.ai_model_id,
          priority: index + 1,
        }),
      ),
    translation_config: planMessageTranslationConfig(),
    service_scenarios: plan.service_scenarios.map((s) => ({
      name: s.name,
      description: s.description,
      instructions: s.instructions,
    })),
    knowledge_base_ids: plan.knowledge_base_ids,
    mcp_tool_ids: plan.mcp_tool_ids,
    strategy_config: planStrategyConfig(),
    auto_messages_config: planAutoMessagesConfig(),
  };
}

const planForm = useForm<PlanFormState>(planFormDefaults());

// 切换到其它方案（同组件复用）/ 后端 props 刷新（保存成功后）时，把表单重置回新方案的快照。
watch(
  () => props.plan.id,
  () => {
    const defaults = planFormDefaults();
    planForm.defaults(defaults);
    planForm.reset();
    planForm.clearErrors();
  },
);

// 提交表单：手动保存方案，保存成功后后端会重定向回详情页并刷新 props。
function savePlan(): void {
  if (planForm.processing) {
    return;
  }

  planForm.put(
    Plan.UpdateReceptionPlanAction.url({
      plan: props.plan.id,
    }),
    {
      preserveScroll: true,
    },
  );
}

// ---------- 服务场景 / KB / MCP 编辑状态 ----------
const scenarioForm = ref<ServiceScenarioFormState>({ mode: 'closed' });
const scenarioDialogOpen = ref(false);

const closeScenarioForm = (): void => {
  scenarioDialogOpen.value = false;
  scenarioForm.value = { mode: 'closed' };
};

const deleteScenarioTarget = ref<{
  index: number;
  name: string;
} | null>(null);

// 服务场景操作：先更新本地 form.service_scenarios，再由表单保存提交。
function handleScenarioSave(draft: ServiceScenarioDraft): void {
  if (scenarioForm.value.mode === 'edit') {
    const editIndex = scenarioForm.value.index;
    const next = planForm.service_scenarios.map((item, index) =>
      index === editIndex ? draft : item,
    );
    planForm.service_scenarios = next;
  } else {
    planForm.service_scenarios = [...planForm.service_scenarios, draft];
  }
  closeScenarioForm();
}

function openCreateScenarioForm(
  template: ServiceScenarioTemplateData | null,
): void {
  scenarioForm.value = { mode: 'create', template };
  scenarioDialogOpen.value = true;
}

function openEditScenarioForm(
  index: number,
  draft: ServiceScenarioDraft,
): void {
  scenarioForm.value = { mode: 'edit', index, draft };
  scenarioDialogOpen.value = true;
}

function confirmDeleteScenario(): void {
  if (!deleteScenarioTarget.value) {
    return;
  }
  const target = deleteScenarioTarget.value.index;
  planForm.service_scenarios = planForm.service_scenarios.filter(
    (_item, index) => index !== target,
  );
  deleteScenarioTarget.value = null;
}

function scenarioError(index: number, field: string): string | undefined {
  const key =
    `service_scenarios.${index}.${field}` as keyof typeof planForm.errors;
  const value = (planForm.errors as Record<string, string | undefined>)[
    key as unknown as string
  ];
  return typeof value === 'string' ? value : undefined;
}

function autoMessageError(
  trigger: AutoMessageTrigger,
  field: keyof AutoMessageDraft,
): string | undefined {
  const key =
    `auto_messages_config.${trigger}.${field}` as keyof typeof planForm.errors;

  return planForm.errors[key];
}

function setAutoMessageEnabled(
  trigger: AutoMessageTrigger,
  checked: boolean,
): void {
  planForm.auto_messages_config[trigger].enabled = Boolean(checked);
}

function hasScenarioErrors(index: number): boolean {
  const prefix = `service_scenarios.${index}.`;
  return Object.keys(planForm.errors).some((k) => k.startsWith(prefix));
}

const kbAssociateDialogOpen = ref(false);
const kbDialogSelection = ref<string[]>([]);
const kbRemoveTarget = ref<string | null>(null);

const mcpAssociateDialogOpen = ref(false);
const mcpDialogSelection = ref<string[]>([]);
const mcpRemoveTarget = ref<string | null>(null);

function kbCategoryIcon(category: string): Component {
  switch (category) {
    case 'qa':
      return HelpCircle;
    case 'wechat_public':
      return Smartphone;
    default:
      return BookOpen;
  }
}

function openKbDialog(): void {
  kbDialogSelection.value = [...planForm.knowledge_base_ids];
  kbAssociateDialogOpen.value = true;
}

function toggleKbDialogSelection(id: string): void {
  if (kbDialogSelection.value.includes(id)) {
    kbDialogSelection.value = kbDialogSelection.value.filter((v) => v !== id);
  } else {
    kbDialogSelection.value = [...kbDialogSelection.value, id];
  }
}

function applyKbSelection(): void {
  planForm.knowledge_base_ids = [...kbDialogSelection.value];
  kbAssociateDialogOpen.value = false;
}

function confirmRemoveKb(): void {
  if (kbRemoveTarget.value) {
    planForm.knowledge_base_ids = planForm.knowledge_base_ids.filter(
      (id) => id !== kbRemoveTarget.value,
    );
    kbRemoveTarget.value = null;
  }
}

function openMcpDialog(): void {
  mcpDialogSelection.value = [...planForm.mcp_tool_ids];
  mcpAssociateDialogOpen.value = true;
}

function toggleMcpDialogSelection(id: string): void {
  if (mcpDialogSelection.value.includes(id)) {
    mcpDialogSelection.value = mcpDialogSelection.value.filter((v) => v !== id);
  } else {
    mcpDialogSelection.value = [...mcpDialogSelection.value, id];
  }
}

function applyMcpSelection(): void {
  planForm.mcp_tool_ids = [...mcpDialogSelection.value];
  mcpAssociateDialogOpen.value = false;
}

function confirmRemoveMcp(): void {
  if (mcpRemoveTarget.value) {
    planForm.mcp_tool_ids = planForm.mcp_tool_ids.filter(
      (id) => id !== mcpRemoveTarget.value,
    );
    mcpRemoveTarget.value = null;
  }
}
</script>

<template>
  <AppLayout>
    <Head :title="props.plan.name" />

    <div class="px-4 py-6 sm:px-6">
      <div class="space-y-6">
        <HeadingSmall :title="props.plan.name" />

        <form class="space-y-6" @submit.prevent="savePlan">
          <div class="border-b">
            <div
              class="flex gap-1 overflow-x-auto"
              role="tablist"
              :aria-label="t('接待方案表单')"
            >
              <button
                v-for="tab in planFormTabs"
                :key="tab.value"
                type="button"
                role="tab"
                :aria-selected="activePlanFormTab === tab.value"
                :class="[
                  'border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                  activePlanFormTab === tab.value
                    ? 'border-foreground text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
                ]"
                @click="activePlanFormTab = tab.value"
              >
                {{ t(tab.label) }}
              </button>
            </div>
          </div>

          <PlanBasicsForm
            v-if="
              activePlanFormTab !== 'service_scenarios' &&
              activePlanFormTab !== 'knowledge_bases' &&
              activePlanFormTab !== 'mcp_tools' &&
              activePlanFormTab !== 'auto_messages' &&
              activePlanFormTab !== 'business_hours' &&
              activePlanFormTab !== 'strategy'
            "
            :form="planForm"
            :section="activePlanBasicsSection"
            :llm-model-options="props.llm_model_options"
            :persona-tone-options="props.persona_tone_options"
            :message-translation-failure-mode-options="
              props.message_translation_failure_mode_options
            "
            :plan="props.plan"
          />

          <PlanStrategyForm
            v-else-if="activePlanFormTab === 'strategy'"
            :form="planForm"
          />

          <PlanBusinessHoursForm
            v-else-if="activePlanFormTab === 'business_hours'"
            :form="planForm"
          />

          <div
            v-else-if="activePlanFormTab === 'auto_messages'"
            class="space-y-6"
          >
            <div class="space-y-1">
              <h4 class="text-sm font-semibold">
                {{ t('自动回复') }}
              </h4>
              <p class="text-sm text-muted-foreground">
                {{
                  t('配置会话进入 AI 或人工接待时自动回复给访客的真实消息。')
                }}
              </p>
            </div>

            <div class="space-y-5">
              <div class="grid gap-2">
                <div class="flex items-center justify-between gap-3">
                  <div class="space-y-0.5">
                    <Label for="auto_message_ai_welcome">
                      {{ t('AI 接待欢迎语') }}
                    </Label>
                    <p class="text-xs text-muted-foreground">
                      {{
                        t(
                          '新会话进入 AI 接待或后续由 AI 接管时发送一次，支持 {variable}。',
                          { variable: displayNameVariable },
                        )
                      }}
                    </p>
                  </div>
                  <Switch
                    id="auto_message_ai_welcome"
                    :model-value="
                      planForm.auto_messages_config.ai_welcome.enabled
                    "
                    @update:model-value="
                      (checked) => setAutoMessageEnabled('ai_welcome', checked)
                    "
                  />
                </div>
                <Textarea
                  v-model="planForm.auto_messages_config.ai_welcome.message"
                  class="min-h-24"
                />
                <InputError
                  :message="autoMessageError('ai_welcome', 'message')"
                />
              </div>

              <div class="grid gap-2">
                <div class="flex items-center justify-between gap-3">
                  <div class="space-y-0.5">
                    <Label for="auto_message_teammate_joined">
                      {{ t('客服接入欢迎语') }}
                    </Label>
                    <p class="text-xs text-muted-foreground">
                      {{
                        t('会话首次分配给客服时发送一次，支持 {variable}。', {
                          variable: teammateNameVariable,
                        })
                      }}
                    </p>
                  </div>
                  <Switch
                    id="auto_message_teammate_joined"
                    :model-value="
                      planForm.auto_messages_config.teammate_joined.enabled
                    "
                    @update:model-value="
                      (checked) =>
                        setAutoMessageEnabled('teammate_joined', checked)
                    "
                  />
                </div>
                <Textarea
                  v-model="
                    planForm.auto_messages_config.teammate_joined.message
                  "
                  class="min-h-24"
                />
                <InputError
                  :message="autoMessageError('teammate_joined', 'message')"
                />
              </div>

              <div class="grid gap-2">
                <div class="flex items-center justify-between gap-3">
                  <div class="space-y-0.5">
                    <Label for="auto_message_teammate_transferred">
                      {{ t('客服转接欢迎语') }}
                    </Label>
                    <p class="text-xs text-muted-foreground">
                      {{
                        t('会话转接给另一位客服时发送一次，支持 {variable}。', {
                          variable: teammateNameVariable,
                        })
                      }}
                    </p>
                  </div>
                  <Switch
                    id="auto_message_teammate_transferred"
                    :model-value="
                      planForm.auto_messages_config.teammate_transferred.enabled
                    "
                    @update:model-value="
                      (checked) =>
                        setAutoMessageEnabled('teammate_transferred', checked)
                    "
                  />
                </div>
                <Textarea
                  v-model="
                    planForm.auto_messages_config.teammate_transferred.message
                  "
                  class="min-h-24"
                />
                <InputError
                  :message="autoMessageError('teammate_transferred', 'message')"
                />
              </div>
            </div>
          </div>

          <!-- 服务场景 -->
          <div
            v-else-if="activePlanFormTab === 'service_scenarios'"
            class="space-y-4"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="space-y-0.5">
                <h4 class="text-sm font-semibold">
                  {{ t('服务场景') }}
                </h4>
                <p class="text-xs text-muted-foreground">
                  {{
                    t('定义任务智能体在不同场景下的处理指令，接待时按需调用。')
                  }}
                </p>
              </div>
              <DropdownMenu>
                <DropdownMenuTrigger as-child>
                  <Button type="button" size="sm" variant="outline">
                    {{ t('添加') }}
                    <ChevronDown class="ml-1 h-3.5 w-3.5" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-52">
                  <DropdownMenuItem @select="openCreateScenarioForm(null)">
                    {{ t('空白服务场景') }}
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    v-for="template in props.service_scenario_templates"
                    :key="template.code"
                    @select="openCreateScenarioForm(template)"
                  >
                    {{ template.name }}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>

            <div
              v-if="planForm.service_scenarios.length > 0"
              class="grid gap-3 sm:grid-cols-2"
            >
              <div
                v-for="(scenario, idx) in planForm.service_scenarios"
                :key="`${scenario.name || 'scenario'}-${idx}`"
                :class="[
                  'flex flex-col gap-2 rounded-lg border p-3',
                  hasScenarioErrors(idx)
                    ? 'border-destructive bg-destructive/5'
                    : '',
                ]"
              >
                <div class="flex items-start justify-between gap-2">
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">
                      {{ scenario.name || '-' }}
                    </p>
                    <p
                      v-if="scenario.description"
                      class="mt-0.5 line-clamp-2 text-xs text-muted-foreground"
                    >
                      {{ scenario.description }}
                    </p>
                  </div>
                  <div class="flex shrink-0 items-center gap-1">
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      class="h-7 w-7 text-muted-foreground hover:text-foreground"
                      :aria-label="t('编辑')"
                      @click="openEditScenarioForm(idx, scenario)"
                    >
                      <Pencil class="h-3.5 w-3.5" />
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      class="h-7 w-7 text-muted-foreground hover:text-destructive"
                      :aria-label="t('删除')"
                      @click="
                        deleteScenarioTarget = {
                          index: idx,
                          name: scenario.name,
                        }
                      "
                    >
                      <Trash2 class="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </div>
                <div v-if="hasScenarioErrors(idx)" class="space-y-1">
                  <InputError :message="scenarioError(idx, 'name')" />
                  <InputError :message="scenarioError(idx, 'description')" />
                  <InputError :message="scenarioError(idx, 'instructions')" />
                </div>
              </div>
            </div>
            <p
              v-else
              class="rounded-lg border border-dashed py-8 text-center text-xs text-muted-foreground"
            >
              {{ t('暂无服务场景') }}
            </p>
            <InputError
              v-if="
                typeof (planForm.errors as Record<string, unknown>)
                  .service_scenarios === 'string'
              "
              :message="
                (planForm.errors as Record<string, string>).service_scenarios
              "
            />
          </div>

          <!-- 关联知识库 -->
          <div
            v-else-if="activePlanFormTab === 'knowledge_bases'"
            class="space-y-4"
          >
            <div class="flex items-center justify-between gap-3">
              <div class="space-y-0.5">
                <h4 class="text-sm font-semibold">
                  {{ t('关联知识库') }}
                </h4>
                <p class="text-xs text-muted-foreground">
                  {{
                    t(
                      '选择此方案中任务智能体可以检索的知识库，所有服务场景共享。',
                    )
                  }}
                </p>
              </div>
              <Button
                type="button"
                size="sm"
                variant="outline"
                @click="openKbDialog"
              >
                {{ t('关联知识库') }}
              </Button>
            </div>

            <div
              v-if="planForm.knowledge_base_ids.length > 0"
              class="grid gap-3 sm:grid-cols-2"
            >
              <div
                v-for="kbId in planForm.knowledge_base_ids"
                :key="kbId"
                class="flex items-start gap-3 rounded-lg border p-3"
              >
                <div class="mt-0.5 shrink-0">
                  <component
                    :is="
                      kbCategoryIcon(
                        props.knowledge_base_options.find(
                          (kb) => kb.id === kbId,
                        )?.category ?? '',
                      )
                    "
                    class="h-4 w-4 text-muted-foreground"
                  />
                </div>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium">
                    {{
                      props.knowledge_base_options.find((kb) => kb.id === kbId)
                        ?.name ?? kbId
                    }}
                  </p>
                  <p class="text-xs text-muted-foreground">
                    {{
                      props.knowledge_base_options.find((kb) => kb.id === kbId)
                        ?.category_label ?? ''
                    }}
                  </p>
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  class="h-7 w-7 shrink-0 text-muted-foreground hover:text-destructive"
                  :aria-label="t('取消关联')"
                  @click="kbRemoveTarget = kbId"
                >
                  <X class="h-3.5 w-3.5" />
                </Button>
              </div>
            </div>
            <p
              v-else
              class="rounded-lg border border-dashed py-8 text-center text-xs text-muted-foreground"
            >
              {{ t('暂未关联知识库') }}
            </p>
            <InputError
              v-if="
                typeof (planForm.errors as Record<string, unknown>)
                  .knowledge_base_ids === 'string'
              "
              :message="
                (planForm.errors as Record<string, string>).knowledge_base_ids
              "
            />
          </div>

          <!-- MCP 工具 -->
          <div v-else-if="activePlanFormTab === 'mcp_tools'" class="space-y-4">
            <div class="flex items-center justify-between gap-3">
              <div class="space-y-0.5">
                <h4 class="text-sm font-semibold">
                  {{ t('MCP 工具') }}
                </h4>
                <p class="text-xs text-muted-foreground">
                  {{
                    t(
                      '选择此方案中任务智能体可以调用的 MCP 工具，所有服务场景共享。',
                    )
                  }}
                </p>
              </div>
              <Button
                type="button"
                size="sm"
                variant="outline"
                @click="openMcpDialog"
              >
                {{ t('关联工具') }}
              </Button>
            </div>

            <div
              v-if="planForm.mcp_tool_ids.length > 0"
              class="grid gap-3 sm:grid-cols-2"
            >
              <div
                v-for="toolId in planForm.mcp_tool_ids"
                :key="toolId"
                class="flex items-center justify-between gap-2 rounded-lg border p-3"
              >
                <div class="min-w-0">
                  <p class="truncate text-sm font-medium">
                    {{
                      props.mcp_tool_options.find((tool) => tool.id === toolId)
                        ?.name ?? toolId
                    }}
                  </p>
                  <p
                    v-if="
                      props.mcp_tool_options.find((tool) => tool.id === toolId)
                        ?.description
                    "
                    class="mt-0.5 line-clamp-1 text-xs text-muted-foreground"
                  >
                    {{
                      props.mcp_tool_options.find((tool) => tool.id === toolId)
                        ?.description
                    }}
                  </p>
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  class="h-7 w-7 shrink-0 text-muted-foreground hover:text-destructive"
                  :aria-label="t('取消关联')"
                  @click="mcpRemoveTarget = toolId"
                >
                  <X class="h-3.5 w-3.5" />
                </Button>
              </div>
            </div>
            <p
              v-else
              class="rounded-lg border border-dashed py-8 text-center text-xs text-muted-foreground"
            >
              {{ t('暂未关联 MCP 工具') }}
            </p>
            <InputError
              v-if="
                typeof (planForm.errors as Record<string, unknown>)
                  .mcp_tool_ids === 'string'
              "
              :message="
                (planForm.errors as Record<string, string>).mcp_tool_ids
              "
            />
          </div>

          <FormActions
            class="border-t pt-6"
            :submit-label="t('保存')"
            :processing="planForm.processing"
            :cancel-href="listUrl"
            :cancel-label="t('返回')"
          />
        </form>
      </div>
    </div>

    <ConfirmDeleteDialog
      :open="deleteScenarioTarget !== null"
      :title="t('确认移除该服务场景？')"
      :detail-title="deleteScenarioTarget?.name"
      :detail-description="
        t(
          '确认后会从当前表单移除，点击保存后生效；进行中会话沿用其锁定的配置不受影响。',
        )
      "
      :processing="false"
      @update:open="(value) => !value && (deleteScenarioTarget = null)"
      @confirm="confirmDeleteScenario"
    />

    <!-- 服务场景新建 / 编辑 Dialog -->
    <Dialog
      :open="scenarioDialogOpen"
      @update:open="(v) => !v && closeScenarioForm()"
    >
      <DialogContent class="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {{
              scenarioForm.mode === 'edit'
                ? t('编辑服务场景')
                : t('新建服务场景')
            }}
          </DialogTitle>
          <DialogDescription>
            {{
              scenarioForm.mode === 'edit'
                ? t('修改后会更新当前表单，点击保存后生效。')
                : scenarioForm.mode === 'create' && scenarioForm.template
                  ? t('已基于模板预填字段，可根据业务需要调整后保存。')
                  : t('为接待方案添加一个服务场景，定义任务处理器的指令。')
            }}
          </DialogDescription>
        </DialogHeader>
        <ServiceScenarioFormPanel
          v-if="scenarioForm.mode !== 'closed'"
          :mode="scenarioForm.mode === 'edit' ? 'edit' : 'create'"
          :scenario="scenarioForm.mode === 'edit' ? scenarioForm.draft : null"
          :initial-template="
            scenarioForm.mode === 'create' ? scenarioForm.template : null
          "
          @cancel="closeScenarioForm"
          @save="handleScenarioSave"
        />
      </DialogContent>
    </Dialog>

    <!-- 关联知识库 Dialog -->
    <Dialog v-model:open="kbAssociateDialogOpen">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{{ t('关联知识库') }}</DialogTitle>
          <DialogDescription>
            {{
              t(
                '选择要关联到此方案的知识库，任务智能体在处理场景时可以检索这些知识库。',
              )
            }}
          </DialogDescription>
        </DialogHeader>
        <div class="max-h-80 space-y-1.5 overflow-y-auto py-1">
          <p
            v-if="props.knowledge_base_options.length === 0"
            class="py-4 text-center text-sm text-muted-foreground"
          >
            {{ t('暂无可用知识库') }}
          </p>
          <button
            v-for="kb in props.knowledge_base_options"
            :key="kb.id"
            type="button"
            :class="[
              'flex w-full items-center gap-3 rounded-lg border px-3 py-2.5 text-left transition-colors',
              kbDialogSelection.includes(kb.id)
                ? 'border-foreground bg-accent'
                : 'hover:bg-muted',
            ]"
            @click="toggleKbDialogSelection(kb.id)"
          >
            <component
              :is="kbCategoryIcon(kb.category)"
              class="h-4 w-4 shrink-0 text-muted-foreground"
            />
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium">{{ kb.name }}</p>
              <p class="text-xs text-muted-foreground">
                {{ kb.category_label }}
              </p>
            </div>
            <Check
              v-if="kbDialogSelection.includes(kb.id)"
              class="h-4 w-4 shrink-0"
            />
          </button>
        </div>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="kbAssociateDialogOpen = false"
          >
            {{ t('取消') }}
          </Button>
          <Button type="button" @click="applyKbSelection">
            {{ t('确认') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- 关联 MCP 工具 Dialog -->
    <Dialog v-model:open="mcpAssociateDialogOpen">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{{ t('关联 MCP 工具') }}</DialogTitle>
          <DialogDescription>
            {{
              t(
                '选择要关联到此方案的 MCP 工具，任务智能体在处理场景时可以调用这些工具。',
              )
            }}
          </DialogDescription>
        </DialogHeader>
        <div class="max-h-80 space-y-1.5 overflow-y-auto py-1">
          <p
            v-if="props.mcp_tool_options.length === 0"
            class="py-4 text-center text-sm text-muted-foreground"
          >
            {{ t('暂无可用 MCP 工具') }}
          </p>
          <button
            v-for="tool in props.mcp_tool_options"
            :key="tool.id"
            type="button"
            :class="[
              'flex w-full items-center gap-2 rounded-lg border px-3 py-2.5 text-left transition-colors',
              mcpDialogSelection.includes(tool.id)
                ? 'border-foreground bg-accent'
                : 'hover:bg-muted',
            ]"
            @click="toggleMcpDialogSelection(tool.id)"
          >
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium">{{ tool.name }}</p>
              <p
                v-if="tool.description"
                class="line-clamp-1 text-xs text-muted-foreground"
              >
                {{ tool.description }}
              </p>
            </div>
            <Check
              v-if="mcpDialogSelection.includes(tool.id)"
              class="h-4 w-4 shrink-0"
            />
          </button>
        </div>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="mcpAssociateDialogOpen = false"
          >
            {{ t('取消') }}
          </Button>
          <Button type="button" @click="applyMcpSelection">
            {{ t('确认') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDeleteDialog
      :open="kbRemoveTarget !== null"
      :title="t('确认取消关联？')"
      :detail-title="
        props.knowledge_base_options.find((kb) => kb.id === kbRemoveTarget)
          ?.name
      "
      :detail-description="
        t('取消关联后该知识库将不再被此方案检索，点击保存后生效。')
      "
      :confirm-label="t('取消关联')"
      :processing-label="t('处理中...')"
      :processing="false"
      @update:open="(v) => !v && (kbRemoveTarget = null)"
      @confirm="confirmRemoveKb"
    />

    <ConfirmDeleteDialog
      :open="mcpRemoveTarget !== null"
      :title="t('确认取消关联？')"
      :detail-title="
        props.mcp_tool_options.find((tool) => tool.id === mcpRemoveTarget)?.name
      "
      :detail-description="
        t('取消关联后该工具将不再被此方案的任务智能体调用，点击保存后生效。')
      "
      :confirm-label="t('取消关联')"
      :processing-label="t('处理中...')"
      :processing="false"
      @update:open="(v) => !v && (mcpRemoveTarget = null)"
      @confirm="confirmRemoveMcp"
    />
  </AppLayout>
</template>
