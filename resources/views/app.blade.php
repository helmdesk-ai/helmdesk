<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- 在页面渲染前根据系统偏好应用暗色模式，避免首屏闪烁。 --}}
    <script>
      (function() {
        const appearance = '{{ $appearance ?? "system" }}';

        if (appearance === 'system') {
          const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

          if (prefersDark) {
            document.documentElement.classList.add('dark');
          }
        }
      })();
    </script>

    {{-- 在 CSS 加载前设置 HTML 背景色，避免主题切换时露出错误底色。 --}}
    <style>
      html {
        background-color: oklch(1 0 0);
      }

      html.dark {
        background-color: oklch(0.145 0 0);
      }
    </style>

    <title inertia>{{ config('app.name', 'HelmDesk') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    @vite('resources/js/app.ts')
    @inertiaHead
  </head>
  <body class="font-sans antialiased">
    @inertia
  </body>
</html>
