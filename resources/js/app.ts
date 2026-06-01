/**
 * 文件说明：前端浏览器入口，初始化 Inertia、Vue 插件、主题和语言环境。
 */
import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, Fragment, h } from 'vue';
import Toaster from './components/ui/toast/Toaster.vue';
import { initializeTheme } from './composables/useAppearance';
import { initializeLocale } from './composables/useI18n';
import { initializeTimezone } from './composables/useTimezone';

const appName = import.meta.env.VITE_APP_NAME;
if (!appName) {
  throw new Error('VITE_APP_NAME is required.');
}

createInertiaApp({
  title: (title) => (title ? `${title} - ${appName}` : appName),
  resolve: (name) =>
    resolvePageComponent(
      `./pages/${name}.vue`,
      import.meta.glob<DefineComponent>('./pages/**/*.vue'),
    ),
  setup({ el, App, props, plugin }) {
    const sharedAuth = (
      props.initialPage.props as {
        auth?: {
          user?: { locale?: string | null; timezone?: string | null } | null;
        };
      }
    ).auth;

    initializeLocale(sharedAuth?.user?.locale);
    initializeTimezone(sharedAuth?.user?.timezone);

    // 把 Toaster 与 Inertia 的 App 作为兄弟节点挂在根级，避免随 page 切换 unmount 导致动画重放、闪烁。
    createApp({ render: () => h(Fragment, null, [h(App, props), h(Toaster)]) })
      .use(plugin)
      .mount(el);
  },
  progress: {
    color: '#4B5563',
  },
});

// 页面加载时恢复明暗主题。
initializeTheme();
