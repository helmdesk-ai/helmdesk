/**
 * 文件说明：前端通用工具，提供页面和组合式逻辑复用的辅助能力。
 */
import DOMPurify, { type Config as DOMPurifyConfig } from 'dompurify';
import { Marked } from 'marked';

// 浮动 AI 助手用到的 Markdown 渲染器：对所有外链强制 target=_blank + rel=noreferrer，
// 并且把不安全的 javascript: / data: 链接整体扔掉。
//
// 用 marked + DOMPurify 而不是自研解析：自研那一版在递归深度、非闭合 token、HTML 转义
// 上都很难做到 100% 安全；marked 是事实标准，DOMPurify 是 OWASP 推荐的 XSS sanitizer，
// 在 streaming 场景下也能稳定渲染半成品 token。
const marked = new Marked({
  // GitHub-flavored Markdown：表格、删除线、自动链接等社区习惯。
  gfm: true,
  // 把单换行视为 <br>，更贴近 chat 气泡里手敲的内容。
  breaks: true,
});

const SANITIZE_OPTIONS: DOMPurifyConfig = {
  // 只保留我们 chat 气泡里真的会用到的标签，把 form / iframe / svg 等高风险标签整体砍掉。
  ALLOWED_TAGS: [
    'a',
    'b',
    'blockquote',
    'br',
    'code',
    'del',
    'em',
    'h1',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'hr',
    'i',
    'img',
    'li',
    'ol',
    'p',
    'pre',
    'span',
    'strong',
    'sub',
    'sup',
    'table',
    'tbody',
    'td',
    'th',
    'thead',
    'tr',
    'ul',
  ],
  ALLOWED_ATTR: ['href', 'title', 'alt', 'src', 'target', 'rel', 'class'],
  // 强制只接受 http(s) / mailto / 协议相对 / 锚点 / 内嵌图片的 data:image。
  ALLOWED_URI_REGEXP:
    /^(?:(?:https?|mailto|tel):|#|\/\/|data:image\/(?:png|jpeg|gif|webp|svg\+xml);base64,)/i,
};

// SSR 注意事项：DOMPurify 在 Node 侧（无 window）创建出来的实例**只挂了** version / removed /
// isSupported = false 三个字段，addHook / sanitize 这些方法压根没定义。如果在 import 时直接
// 调 addHook 会立刻 TypeError 把 SSR 整个页面渲染搞挂（参见 dompurify 3.x 的 createDOMPurify
// 提前 return 路径）。
//
// 我们通过两层保护规避：
//   1. 用 DOMPurify.isSupported 判断当前是不是真能用 sanitize；
//   2. 把 addHook 也延迟到第一次调用 renderMarkdownToSafeHtml 时再注册——这样即使有人在 Node
//      上意外 import 这个模块（比如 vue-tsc / SSR 渲染），也不会在 import 阶段炸掉。
//
// SSR 阶段返回空串是合理回退：assistant 气泡的 markdown 内容只有用户交互之后才会出现，
// 首屏渲染时 messages 一定是空的。
let hooksRegistered = false;

const ensureHooksRegistered = () => {
  if (hooksRegistered) return;
  hooksRegistered = true;

  // 让所有 <a> 都在新标签打开，避免覆盖掉浮动框/收件箱本身的页面。
  DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A') {
      node.setAttribute('target', '_blank');
      node.setAttribute('rel', 'noopener noreferrer');
    }
  });
};

/**
 * 把一段（可能仍在流式增量中的）markdown 文本渲染成可以直接 v-html 注入的 HTML。
 *
 * marked 对未闭合的代码块/链接是宽容的（会按行尾收尾），所以流式输出的中间态
 * 不会抛错，最多产生一个看起来稍微截断的段落。
 */
export const renderMarkdownToSafeHtml = (source: string): string => {
  if (!source) {
    return '';
  }

  // SSR / 任何无 DOM 的运行环境下，DOMPurify 会自己把 isSupported 设成 false。
  // 这种情况下退回空串而不是把 marked 输出直出（marked 不做 sanitize，会有 XSS 风险）。
  if (!DOMPurify.isSupported) {
    return '';
  }

  ensureHooksRegistered();

  const rawHtml = marked.parse(source, { async: false }) as string;
  return DOMPurify.sanitize(rawHtml, SANITIZE_OPTIONS) as unknown as string;
};
