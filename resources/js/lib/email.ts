/**
 * 文件说明：前端通用工具，提供页面和组合式逻辑复用的辅助能力。
 */

const EMAIL_REGEX =
  /^[A-Za-z0-9._%+-]+@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+$/;

export const EMAIL_MAX_LENGTH = 254;

export const normalizeEmail = (value: string): string => {
  return value.trim();
};

export const isLikelyValidEmail = (value: string): boolean => {
  const normalized = normalizeEmail(value);

  if (normalized === '' || normalized.length > EMAIL_MAX_LENGTH) {
    return false;
  }

  return EMAIL_REGEX.test(normalized);
};
