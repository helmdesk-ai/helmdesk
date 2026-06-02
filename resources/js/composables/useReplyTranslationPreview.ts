/**
 * 收件箱回复翻译预览的组合式函数。
 *
 * 当客服语言与访客语言不同时，自动将回复内容翻译成访客语言并展示预览，
 * 支持客服手动编辑译文，提交时将译文一并写入表单。
 */
import { useI18n } from '@/composables/useI18n';
import { localeMatches } from '@/lib/locale';
import inboxActions from '@/routes/workspace/inbox';
import type { InboxSelectionData } from '@/types/generated';
import axios from 'axios';
import {
  type ComputedRef,
  type Ref,
  computed,
  onUnmounted,
  ref,
  watch,
} from 'vue';

export interface UseReplyTranslationPreviewOptions {
  /** 当前选中的会话数据 */
  selection: ComputedRef<InboxSelectionData | null>;
  /** 当前登录用户语言 */
  currentUserLocale: ComputedRef<string>;
  /** 回复内容（绑定到 replyForm.content） */
  replyContent: Ref<string>;
  /** 是否启用回复翻译 */
  enabled: Ref<boolean>;
}

/** 翻译结果需要写入的表单字段 */
export interface ReplyTranslationFormFields {
  visitor_content: string | null;
  visitor_locale: string | null;
  source_locale: string | null;
}

export interface UseReplyTranslationPreviewReturn {
  /** 翻译草稿文本 */
  draft: Ref<string>;
  /** 是否正在请求翻译 */
  loading: Ref<boolean>;
  /** 用户是否手动编辑过译文 */
  touched: Ref<boolean>;
  /** 翻译错误信息 */
  error: Ref<string | null>;
  /** 访客语言（翻译接口返回） */
  visitorLocale: Ref<string | null>;
  /** 原文语言（翻译接口返回） */
  sourceLocale: Ref<string | null>;
  /** 翻译是否就绪，可以提交 */
  ready: ComputedRef<boolean>;
  /** 翻译前置条件提示信息 */
  requirementMessage: ComputedRef<string | null>;
  /** 是否应展示翻译预览区域 */
  showPreview: ComputedRef<boolean>;
  /** 预览标题文案 */
  title: ComputedRef<string>;
  /** 回复自动翻译是否已激活（开关开启且有权限且有访客语言） */
  active: ComputedRef<boolean>;
  /** 当前用户语言是否与访客语言匹配（匹配时不需要翻译） */
  matchesVisitorLocale: ComputedRef<boolean>;
  /** 预期的访客语言 */
  expectedVisitorLocale: ComputedRef<string | null>;
  /** 将翻译结果写入表单字段 */
  applyToForm: (form: ReplyTranslationFormFields) => void;
  /** 清除翻译预览状态并重置表单翻译字段 */
  clear: (form?: ReplyTranslationFormFields) => void;
  /** 资源清理（用于 onUnmounted） */
  cleanup: () => void;
  /** 主动触发翻译调度（外部在 enabled 切换为 true 时调用） */
  schedule: () => void;
}

/** 翻译请求去抖延迟（毫秒） */
const REPLY_TRANSLATION_DEBOUNCE_MS = 600;

export function useReplyTranslationPreview(
  options: UseReplyTranslationPreviewOptions,
): UseReplyTranslationPreviewReturn {
  const { selection, currentUserLocale, replyContent, enabled } =
    options;

  const { t } = useI18n();

  // --- 内部状态 ---

  const replyTranslationDraft = ref('');
  const replyTranslationSource = ref('');
  const replyVisitorLocale = ref<string | null>(null);
  const replySourceLocale = ref<string | null>(null);
  const replyTranslationLoading = ref(false);
  const replyTranslationTouched = ref(false);
  const replyTranslationError = ref<string | null>(null);
  let replyTranslationTimer: number | null = null;
  let replyTranslationController: AbortController | null = null;

  // --- 计算属性 ---

  const replyExpectedVisitorLocale = computed(
    () => selection.value?.reply_visitor_locale ?? null,
  );

  const replyMatchesVisitorLocale = computed(() =>
    localeMatches(currentUserLocale.value, replyExpectedVisitorLocale.value),
  );

  const replyAutoTranslationActive = computed(
    () =>
      enabled.value &&
      Boolean(selection.value?.can_reply) &&
      replyExpectedVisitorLocale.value !== null,
  );

  const replyTranslationReady = computed(() => {
    if (!replyAutoTranslationActive.value) {
      return true;
    }

    const content = replyContent.value.trim();
    if (content === '' || replyExpectedVisitorLocale.value === null) {
      return false;
    }
    if (replyMatchesVisitorLocale.value) {
      return true;
    }

    return (
      !replyTranslationLoading.value &&
      replyTranslationError.value === null &&
      replyTranslationSource.value === content &&
      replyVisitorLocale.value === replyExpectedVisitorLocale.value &&
      replySourceLocale.value !== null &&
      replyTranslationDraft.value.trim().length > 0
    );
  });

  const replyTranslationRequirementMessage = computed(() => {
    if (!replyAutoTranslationActive.value || replyContent.value.trim() === '') {
      return null;
    }
    if (replyExpectedVisitorLocale.value === null) {
      return t('请先设置访客语言');
    }
    if (replyTranslationLoading.value) {
      return null;
    }
    if (replyTranslationError.value !== null) {
      return replyTranslationError.value;
    }
    if (!replyTranslationReady.value) {
      return t('请先确认访客将看到的内容');
    }

    return null;
  });

  const showReplyTranslationPreview = computed(
    () =>
      (replyAutoTranslationActive.value &&
        replyContent.value.trim().length > 0 &&
        !replyMatchesVisitorLocale.value) ||
      replyTranslationLoading.value ||
      replyTranslationDraft.value.trim().length > 0 ||
      replyTranslationError.value !== null,
  );

  const replyTranslationTitle = computed(() => {
    return t('访客将看到');
  });

  // --- 函数 ---

  function clearReplyTranslationPreview(
    form?: ReplyTranslationFormFields,
  ): void {
    if (replyTranslationTimer !== null) {
      window.clearTimeout(replyTranslationTimer);
      replyTranslationTimer = null;
    }
    replyTranslationController?.abort();
    replyTranslationController = null;
    replyTranslationDraft.value = '';
    replyTranslationSource.value = '';
    replyVisitorLocale.value = null;
    replySourceLocale.value = null;
    replyTranslationLoading.value = false;
    replyTranslationTouched.value = false;
    replyTranslationError.value = null;
    if (form) {
      form.visitor_content = null;
      form.visitor_locale = null;
      form.source_locale = null;
    }
  }

  function applyReplyTranslationToForm(form: ReplyTranslationFormFields): void {
    const content = replyContent.value.trim();
    if (
      replyAutoTranslationActive.value &&
      replyMatchesVisitorLocale.value &&
      content !== ''
    ) {
      form.visitor_content = content;
      form.visitor_locale = replyExpectedVisitorLocale.value;
      form.source_locale = currentUserLocale.value;
      return;
    }

    const text = replyTranslationDraft.value.trim();
    form.visitor_content =
      text !== '' && replyVisitorLocale.value !== null ? text : null;
    form.visitor_locale = text !== '' ? replyVisitorLocale.value : null;
    form.source_locale = text !== '' ? replySourceLocale.value : null;
  }

  async function requestReplyTranslationPreview(
    conversationId: string,
    content: string,
  ): Promise<void> {
    replyTranslationController?.abort();
    const controller = new AbortController();
    replyTranslationController = controller;
    replyTranslationLoading.value = true;
    replyTranslationError.value = null;

    try {
      const response = await axios.post<{
        visitor_content: string | null;
        visitor_locale: string | null;
        source_locale: string | null;
      }>(
        inboxActions.conversations.reply.translationPreview.url({
          conversation: conversationId,
        }),
        { content },
        { signal: controller.signal },
      );

      if (
        controller.signal.aborted ||
        selection.value?.conversation.id !== conversationId ||
        replyContent.value.trim() !== content
      ) {
        return;
      }

      replyTranslationSource.value = content;
      replyVisitorLocale.value = response.data.visitor_locale;
      replySourceLocale.value = response.data.source_locale;
      replyTranslationError.value =
        response.data.visitor_content === null ? t('翻译失败') : null;
      if (!replyTranslationTouched.value) {
        replyTranslationDraft.value = response.data.visitor_content ?? '';
        // draft 变化后由外部 watcher 调用 applyToForm 同步到表单
      }
    } catch (error) {
      if (controller.signal.aborted) {
        return;
      }
      replyTranslationError.value =
        error instanceof Error ? error.message : t('翻译失败');
    } finally {
      if (replyTranslationController === controller) {
        replyTranslationController = null;
        replyTranslationLoading.value = false;
      }
    }
  }

  function scheduleReplyTranslationPreview(): void {
    if (replyTranslationTimer !== null) {
      window.clearTimeout(replyTranslationTimer);
      replyTranslationTimer = null;
    }

    const conversationId = selection.value?.conversation.id;
    const content = replyContent.value.trim();
    if (
      !conversationId ||
      !selection.value?.can_reply ||
      content === '' ||
      !replyAutoTranslationActive.value ||
      replyExpectedVisitorLocale.value === null
    ) {
      clearReplyTranslationPreview();
      return;
    }

    if (content !== replyTranslationSource.value) {
      replyTranslationTouched.value = false;
      replyTranslationDraft.value = '';
      replyVisitorLocale.value = null;
      replySourceLocale.value = null;
    }

    if (replyMatchesVisitorLocale.value) {
      replyTranslationController?.abort();
      replyTranslationController = null;
      replyTranslationLoading.value = false;
      replyTranslationError.value = null;
      replyTranslationTouched.value = false;
      replyTranslationDraft.value = '';
      replyTranslationSource.value = content;
      replyVisitorLocale.value = replyExpectedVisitorLocale.value;
      replySourceLocale.value = currentUserLocale.value;
      // draft 变化后由外部 watcher 调用 applyToForm 同步到表单
      return;
    }

    replyTranslationTimer = window.setTimeout(() => {
      replyTranslationTimer = null;
      void requestReplyTranslationPreview(conversationId, content);
    }, REPLY_TRANSLATION_DEBOUNCE_MS);
  }

  // --- 监听器 ---

  // 回复内容、会话、权限、访客语言等变化时重新调度翻译预览
  watch(
    () => [
      replyContent.value,
      selection.value?.conversation.id,
      selection.value?.can_reply,
      selection.value?.reply_visitor_locale,
      enabled.value,
      currentUserLocale.value,
    ],
    () => scheduleReplyTranslationPreview(),
  );

  // --- 清理 ---

  function cleanup(): void {
    if (replyTranslationTimer !== null) {
      window.clearTimeout(replyTranslationTimer);
      replyTranslationTimer = null;
    }
    replyTranslationController?.abort();
    replyTranslationController = null;
  }

  onUnmounted(cleanup);

  return {
    draft: replyTranslationDraft,
    loading: replyTranslationLoading,
    touched: replyTranslationTouched,
    error: replyTranslationError,
    visitorLocale: replyVisitorLocale,
    sourceLocale: replySourceLocale,
    ready: replyTranslationReady,
    requirementMessage: replyTranslationRequirementMessage,
    showPreview: showReplyTranslationPreview,
    title: replyTranslationTitle,
    active: replyAutoTranslationActive,
    matchesVisitorLocale: replyMatchesVisitorLocale,
    expectedVisitorLocale: replyExpectedVisitorLocale,
    applyToForm: applyReplyTranslationToForm,
    clear: clearReplyTranslationPreview,
    cleanup,
    schedule: scheduleReplyTranslationPreview,
  };
}
