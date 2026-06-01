/**
 * 文件说明：前端组合式逻辑，封装页面间复用的状态和浏览器侧行为。
 */
import type { WorkspaceData } from '@/types/generated';
import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

const readWorkspaces = (value: unknown): WorkspaceData[] => {
  if (!Array.isArray(value)) {
    const { url, component } = getContextForError();
    throw new Error(
      `工作区列表缺失：IdentifyWorkspace 中间件应提供 workspaces。component=${component} url=${url}`,
    );
  }

  return value as WorkspaceData[];
};

function getContextForError() {
  const page = usePage();
  const url =
    (page as any)?.url ??
    (typeof window !== 'undefined' ? window.location.pathname : '');
  const component = (page as any)?.component ?? 'unknown';
  return { url, component };
}

export function useCurrentWorkspace(): ComputedRef<WorkspaceData | null> {
  const page = usePage();
  return computed(() => {
    const slug = (page.props as any)?.workspaceUserContext?.workspace_slug as
      | string
      | null
      | undefined;
    if (!slug) {
      return null;
    }

    const workspaces = readWorkspaces((page.props as any)?.workspaces);
    return workspaces.find((w) => w.slug === slug) ?? null;
  });
}

/**
 * 工作区页面使用：从 `workspaceUserContext.workspace_slug` + `workspaces` 推导当前工作区。
 * 如果缺失会立刻抛错，帮助尽早发现“在错误上下文使用工作区组件”的问题。
 */
export function useRequiredWorkspace(): ComputedRef<WorkspaceData> {
  const page = usePage();

  return computed(() => {
    const slug = (page.props as any)?.workspaceUserContext?.workspace_slug as
      | string
      | null
      | undefined;
    const workspaces = readWorkspaces((page.props as any)?.workspaces);

    const ws = slug ? (workspaces.find((w) => w.slug === slug) ?? null) : null;

    if (!ws) {
      const { url, component } = getContextForError();
      throw new Error(
        `当前工作区缺失：该页面/组件需要工作区上下文（IdentifyWorkspace 中间件应提供 workspaceUserContext + workspaces）。component=${component} url=${url}`,
      );
    }

    return ws;
  });
}
