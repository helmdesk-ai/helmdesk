import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'url';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const vitePort = Number(env.VITE_PORT || 3000);
  const hmrHost = env.VITE_HMR_HOST || 'localhost';
  const hmrClientPort = Number(env.VITE_HMR_CLIENT_PORT || vitePort);

  // 允许的 CORS origin：以 APP_URL 为基础，再补 localhost / 127.0.0.1 的等价别名。
  const corsOrigins = (() => {
    if (!env.APP_URL) {
      return ['http://localhost:8888', 'http://127.0.0.1:8888'];
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
