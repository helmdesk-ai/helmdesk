/**
 * 文件说明：封装 AI 助手可选模型的读取、按供应商分组与本地存储 key 拼接，
 * 供 AiAssistantWidget 与收件箱页面复用。
 */
import { useRequiredSystem } from '@/composables/useSystemContext';
import type { AiModelOptionData } from '@/types/generated';
import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

export interface GroupedAiModelOptions {
  providerName: string;
  options: AiModelOptionData[];
}

export function useAiAssistantModels(): {
  modelOptions: ComputedRef<AiModelOptionData[]>;
  groupedModelOptions: ComputedRef<GroupedAiModelOptions[]>;
  selectedModelStorageKey: ComputedRef<string>;
} {
  const page = usePage();
  const system = useRequiredSystem();

  const modelOptions = computed<AiModelOptionData[]>(() => {
    const options = (page.props as { aiAssistantLlmModelOptions?: unknown })
      .aiAssistantLlmModelOptions;
    if (!Array.isArray(options)) {
      throw new Error('aiAssistantLlmModelOptions is required.');
    }

    return options as AiModelOptionData[];
  });

  const groupedModelOptions = computed<GroupedAiModelOptions[]>(() => {
    const groups = new Map<string, AiModelOptionData[]>();
    for (const option of modelOptions.value) {
      const list = groups.get(option.provider_name) ?? [];
      list.push(option);
      groups.set(option.provider_name, list);
    }

    return Array.from(groups, ([providerName, options]) => ({
      providerName,
      options,
    }));
  });

  const selectedModelStorageKey = computed(
    () => `ai-assistant:selected-model:${system.value.id}`,
  );

  return { modelOptions, groupedModelOptions, selectedModelStorageKey };
}
