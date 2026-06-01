<!--
  文件说明：网站渠道小部件真实嵌入预览，通过公开 widget script 渲染已保存配置。
-->
<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

const props = defineProps<{
  code: string;
  snippet: string;
  refreshKey?: string | number | null;
}>();

const { t } = useI18n();

const previewDocument = computed(
  () => `<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
      html,
      body {
        width: 100%;
        height: 100%;
        margin: 0;
        overflow: hidden;
        background: #fff;
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      }
    </style>
  </head>
  <body>
    ${props.snippet}
  </body>
</html>`,
);
</script>

<template>
  <div class="space-y-3">
    <div class="text-sm font-medium">
      {{ t('预览') }}
    </div>

    <div
      class="h-[calc(100vh-12rem)] max-h-[640px] min-h-[480px] overflow-hidden rounded-lg border bg-background shadow-sm"
    >
      <iframe
        :key="`${props.code}-${props.refreshKey ?? 'initial'}`"
        :srcdoc="previewDocument"
        :title="t('预览')"
        class="h-full w-full border-0 bg-background"
        loading="lazy"
        referrerpolicy="same-origin"
      ></iframe>
    </div>
  </div>
</template>
