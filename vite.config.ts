import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'url';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const vitePort = Number(env.VITE_PORT || 5173);
  const hmrHost = env.VITE_HMR_HOST || 'localhost';
  const hmrClientPort = Number(
    env.VITE_HMR_CLIENT_PORT || env.COMPOSE_DEV_VITE_PORT || vitePort,
  );

  // 允许的 CORS origin：以 APP_URL 为基础，再补 localhost / 127.0.0.1 的等价别名。
  // 之前为了兼容 docker compose 的端口可变把 80 写死了一份，但当 COMPOSE_DEV_HTTP_PORT
  // 真的被改掉时，那个固定的 :80 反而是死代码；这里改成完全跟随 APP_URL 推导，
  // 只在 APP_URL 是 localhost / 127.0.0.1 时再生成另一种主机名的等价 origin。
  const corsOrigins = (() => {
    if (!env.APP_URL) {
      const port = env.COMPOSE_DEV_HTTP_PORT || '80';
      return [`http://localhost:${port}`, `http://127.0.0.1:${port}`];
    }

    const appUrl = env.APP_URL.replace(/\/$/, '');
    try {
      const parsed = new URL(appUrl);
      const out = new Set<string>([appUrl]);
      if (parsed.hostname === 'localhost') {
        parsed.hostname = '127.0.0.1';
        out.add(parsed.origin);
      } else if (parsed.hostname === '127.0.0.1') {
        parsed.hostname = 'localhost';
        out.add(parsed.origin);
      }
      return [...out];
    } catch {
      return [appUrl];
    }
  })();

  return {
    plugins: [
      laravel({
        input: [
          'resources/js/app.ts',
          'resources/js/standalone.ts',
          'resources/js/widget.ts',
          'resources/js/channel-preview.ts',
        ],
        ssr: 'resources/js/ssr.ts',
        refresh: true,
      }),
      tailwindcss(),
      wayfinder({
        formVariants: true,
      }),
      vue({
        template: {
          transformAssetUrls: {
            base: null,
            includeAbsolute: false,
          },
        },
      }),
    ],
    server: {
      host: '0.0.0.0',
      port: vitePort,
      origin: `http://${hmrHost}:${hmrClientPort}`,
      cors: {
        origin: corsOrigins,
      },
      hmr: {
        host: hmrHost,
        clientPort: hmrClientPort,
      },
    },
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
      },
    },
  };
});
