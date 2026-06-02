/**
 * 文件说明：封装单租户后台运行时上下文读取。
 */
import type { WorkspaceUserContextData } from '@/types/generated';
import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

export interface CurrentWorkspaceContext {
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

const readContext = (): WorkspaceUserContextData | null => {
  const page = usePage();
  const context = (page.props as any)?.workspaceUserContext;

  return context && typeof context === 'object'
    ? (context as WorkspaceUserContextData)
    : null;
};

const mapContext = (
  context: WorkspaceUserContextData,
): CurrentWorkspaceContext => ({
  id: context.workspace_slug,
  slug: context.workspace_slug,
  name: context.workspace_name,
  logo_url: context.workspace_logo_url,
});

export function useCurrentWorkspace(): ComputedRef<CurrentWorkspaceContext | null> {
  return computed(() => {
    const context = readContext();

    return context ? mapContext(context) : null;
  });
}

/**
 * 后台页面使用：从 `workspaceUserContext` 读取单租户上下文。
 */
export function useRequiredWorkspace(): ComputedRef<CurrentWorkspaceContext> {
  return computed(() => {
    const context = readContext();

    if (!context) {
      const { url, component } = getContextForError();
      throw new Error(
        `单租户上下文缺失：IdentifyWorkspace 中间件应提供 workspaceUserContext。component=${component} url=${url}`,
      );
    }

    return mapContext(context);
  });
}
