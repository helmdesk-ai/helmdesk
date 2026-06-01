/**
 * 文件说明：前端组合式逻辑，封装页面间复用的状态和浏览器侧行为。
 */
import { defaultLocale, locales, type Locale, type Messages } from '@/locales';
import { onMounted, ref } from 'vue';

const normalizeLocale = (value: unknown): Locale => {
  if (typeof value !== 'string') {
    return defaultLocale;
  }

  const normalized = value.replace('_', '-').toLowerCase();

  if (normalized === 'en' || normalized.startsWith('en-')) {
    return 'en';
  }

  if (normalized === 'zh' || normalized.startsWith('zh-')) {
    return 'zh-CN';
  }

  return defaultLocale;
};

const setCookie = (name: string, value: string, days = 365) => {
  if (typeof document === 'undefined') {
    return;
  }

  const maxAge = days * 24 * 60 * 60;

  document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const getStoredLocale = (): Locale | null => {
  if (typeof window === 'undefined') {
    return null;
  }

  const stored = localStorage.getItem('locale');

  return stored && stored in locales ? (stored as Locale) : null;
};

const getBrowserLocale = (): Locale => {
  if (typeof navigator === 'undefined') {
    return defaultLocale;
  }

  return normalizeLocale(
    [...(navigator.languages ?? []), navigator.language].find((value) =>
      Boolean(value?.trim()),
    ),
  );
};

const resolveInitialLocale = (): Locale =>
  getStoredLocale() ?? getBrowserLocale();

const locale = ref<Locale>(resolveInitialLocale());
const messages = ref<Messages>(locales[locale.value]);

const applyLocale = (
  newLocale: Locale,
  options: { persist?: boolean } = {},
) => {
  locale.value = newLocale;
  messages.value = locales[newLocale];

  if (options.persist === false || typeof window === 'undefined') {
    return;
  }

  localStorage.setItem('locale', newLocale);
  setCookie('locale', newLocale);
};

export function initializeLocale(userLocale?: string | null) {
  if (typeof window === 'undefined') {
    return;
  }

  applyLocale(
    userLocale
      ? normalizeLocale(userLocale)
      : (getStoredLocale() ?? getBrowserLocale()),
  );
}

export function useI18n() {
  onMounted(() => {
    const savedLocale = getStoredLocale();

    if (savedLocale && locales[savedLocale] && savedLocale !== locale.value) {
      applyLocale(savedLocale);
    }
  });

  function updateLocale(newLocale: Locale) {
    if (!locales[newLocale]) {
      console.warn(`Locale "${newLocale}" is not available`);
      return;
    }

    applyLocale(newLocale);
  }

  /**
   * 使用中文 key 翻译，并替换 `{name}` 这类占位符。
   */
  function t(key: string, params?: Record<string, string | number>): string {
    let text =
      messages.value && key in messages.value
        ? (messages.value[key as keyof Messages] as string)
        : key;

    if (params) {
      for (const [name, value] of Object.entries(params)) {
        text = text.replaceAll(`{${name}}`, String(value));
      }
    }

    return text;
  }

  return {
    locale,
    messages,
    updateLocale,
    t,
  };
}
