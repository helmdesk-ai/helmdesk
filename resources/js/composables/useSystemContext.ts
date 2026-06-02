/**
 * 文件说明：封装单租户后台运行时上下文读取。
 */
import type { SystemUserContextData } from '@/types/generated';
import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

export interface CurrentSystemContext {
  id: string;
  slug: string;
  name: string;
  logo_url: string;
}

function getContextForError() {
  const page = usePage();
  const url =
    (page as any)?.url ??
    (typeof window !== 'undefined' ? window.location.pathname : '');
  const component = (page as any)?.component ?? 'unknown';
  return { url, component };
}

const readContext = (): SystemUserContextData | null => {
  const page = usePage();
  const context = (page.props as any)?.systemUserContext;

  return context && typeof context === 'object'
    ? (context as SystemUserContextData)
    : null;
};

const mapContext = (
  context: SystemUserContextData,
): CurrentSystemContext => ({
  id: context.system_slug,
  slug: context.system_slug,
  name: context.system_name,
  logo_url: context.system_logo_url,
});

export function useCurrentSystem(): ComputedRef<CurrentSystemContext | null> {
  return computed(() => {
    const context = readContext();

    return context ? mapContext(context) : null;
  });
}

/**
 * 后台页面使用：从 `systemUserContext` 读取单租户上下文。
 */
export function useRequiredSystem(): ComputedRef<CurrentSystemContext> {
  return computed(() => {
    const context = readContext();

    if (!context) {
      const { url, component } = getContextForError();
      throw new Error(
        `单租户上下文缺失：IdentifySystem 中间件应提供 systemUserContext。component=${component} url=${url}`,
      );
    }

    return mapContext(context);
  });
}
