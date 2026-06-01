/**
 * 文件说明：前端组合式逻辑，封装页面间复用的状态和浏览器侧行为。
 */
import { router } from '@inertiajs/vue3';
import { onUnmounted, ref } from 'vue';
import { useI18n } from './useI18n';

export type ToastType = 'default' | 'success' | 'error' | 'warning' | 'info';

export interface Toast {
  id: string;
  title?: string;
  description?: string;
  type?: ToastType;
  duration?: number;
  action?: {
    label: string;
    onClick: () => void;
  };
}

const DEFAULT_TOAST_DURATION = Number.POSITIVE_INFINITY;

const toasts = ref<Toast[]>([]);
let toastIdCounter = 0;
const dismissTimers = new Map<string, ReturnType<typeof setTimeout>>();

const clearDismissTimer = (id: string): void => {
  const timer = dismissTimers.get(id);
  if (timer) {
    clearTimeout(timer);
    dismissTimers.delete(id);
  }
};

const dismissToast = (id: string): void => {
  clearDismissTimer(id);
  const index = toasts.value.findIndex((t) => t.id === id);
  if (index !== -1) {
    toasts.value.splice(index, 1);
  }
};

export function useToast() {
  const { t } = useI18n();

  const addToast = (toast: Omit<Toast, 'id'>): string => {
    for (const existing of toasts.value) {
      clearDismissTimer(existing.id);
    }

    const id = `toast-${++toastIdCounter}`;
    const duration = toast.duration ?? DEFAULT_TOAST_DURATION;

    toasts.value = [{ id, ...toast, duration }];

    if (duration > 0 && Number.isFinite(duration)) {
      const timer = setTimeout(() => {
        dismissToast(id);
      }, duration);
      dismissTimers.set(id, timer);
    }

    return id;
  };

  const removeToast = (id: string): void => {
    dismissToast(id);
  };

  const toast = {
    success: (message: string) => {
      return addToast({
        title: t('成功'),
        description: message,
        type: 'success',
      });
    },
    error: (message: string) => {
      return addToast({
        title: t('错误'),
        description: message,
        type: 'error',
      });
    },
    warning: (message: string) => {
      return addToast({
        title: t('警告'),
        description: message,
        type: 'warning',
      });
    },
    info: (message: string) => {
      return addToast({ title: t('提示'), description: message, type: 'info' });
    },
    default: (message: string) => {
      return addToast({
        title: t('通知'),
        description: message,
        type: 'default',
      });
    },
  };

  return {
    toasts,
    toast,
    addToast,
    removeToast,
  };
}

let apiInterceptorSetup = false;

/**
 * 统一设置错误处理
 * 同时处理 Inertia 表单错误和 API 请求错误
 */
export function useErrorHandling() {
  const { toast } = useToast();

  const removeErrorListener = router.on('error', (event) => {
    const errors = event.detail.errors as any;

    if (errors?.toast && typeof errors.toast === 'string') {
      toast.error(errors.toast);
    }
  });

  const removeFlashListener = router.on('flash', (event) => {
    const flash = event.detail.flash as any;

    if (flash?.toast && typeof flash.toast === 'object') {
      const { type = 'success', message } = flash.toast;
      if (type !== 'error' && message) {
        const toastFn = toast[type as ToastType];
        if (toastFn) {
          toastFn(message);
        } else {
          toast.default(message);
        }
      }
    }
  });

  onUnmounted(() => {
    removeErrorListener();
    removeFlashListener();
  });

  if (!apiInterceptorSetup) {
    import('axios').then(({ default: axios }) => {
      axios.interceptors.response.use(
        (response) => response,
        (error) => {
          if (
            axios.isCancel(error) ||
            error?.code === 'ERR_CANCELED' ||
            error?.name === 'CanceledError'
          ) {
            return Promise.reject(error);
          }

          const message =
            error.response?.data?.message ||
            error.message ||
            '请求失败，请稍后重试';
          toast.error(message);

          return Promise.reject(error);
        },
      );

      apiInterceptorSetup = true;
    });
  }
}
