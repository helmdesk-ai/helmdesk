/**
 * 模型标签格式化工具，多个接待方案组件共用。
 */

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
 * 将空值统一展示为全角破折号。
 */
export function valueOrDash(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }
  return value;
}
