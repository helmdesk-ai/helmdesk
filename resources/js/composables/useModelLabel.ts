/**
 * 模型标签格式化工具，多个接待方案组件共用。
 */
import type {
  ModelCandidateData,
  ModelInvocationData,
  ModelSelectionStatusData,
} from '@/types/generated';

/**
 * 去掉模型标签末尾的括号附带信息（例如供应商缩写），使展示更简洁。
 */
export function simplifyModelLabel(label: string | null | undefined): string {
  if (!label) {
    return '';
  }
  return label.replace(/\s*\([^()]+\)\s*$/, '').trim();
}

/**
 * 将 ModelInvocationData 转为可展示的模型名称。
 */
export function describeModel(
  invocation: ModelInvocationData | null,
  emptyText: string,
): string {
  if (invocation === null) {
    return emptyText;
  }
  return simplifyModelLabel(invocation.label) || invocation.ai_model_id;
}

/**
 * 优先使用后端解析出的模型状态标签展示当前选中模型。
 */
export function describeSelectedModel(
  invocation: ModelInvocationData | null,
  status: ModelSelectionStatusData | null,
  emptyText: string,
): string {
  return (
    simplifyModelLabel(status?.label) || describeModel(invocation, emptyText)
  );
}

/**
 * 将空值统一展示为全角破折号。
 */
export function valueOrDash(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }
  return value;
}

/**
 * 过滤出 priority > 0 的备用模型。
 */
export function backupCandidates(
  candidates: ModelCandidateData[],
): ModelCandidateData[] {
  return candidates.filter((candidate) => candidate.priority > 0);
}
