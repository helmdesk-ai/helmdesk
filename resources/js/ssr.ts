/**
 * 文件说明：前端 SSR 入口，负责在服务端渲染 Inertia 页面。
 */
import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createSSRApp, DefineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';

const appName = import.meta.env.VITE_APP_NAME;
if (!appName) {
  throw new Error('VITE_APP_NAME is required.');
}

createServer(
  (page) =>
    createInertiaApp({
      page,
      render: renderToString,
      title: (title) => (title ? `${title} - ${appName}` : appName),
      resolve: (name) =>
        resolvePageComponent(
          `./pages/${name}.vue`,
          import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
      setup: ({ App, props, plugin }) =>
        createSSRApp({ render: () => h(App, props) }).use(plugin),
    }),
  { cluster: true },
);
