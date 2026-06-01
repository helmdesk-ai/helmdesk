<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

const props = defineProps<{
  url: string;
  refreshKey?: string | number | null;
}>();

const { t } = useI18n();

const previewUrl = computed(() => {
  if (!props.refreshKey) {
    return props.url;
  }

  const [urlWithoutHash, hash = ''] = props.url.split('#', 2);
  const separator = urlWithoutHash.includes('?') ? '&' : '?';

  return `${urlWithoutHash}${separator}preview_refresh=${encodeURIComponent(String(props.refreshKey))}${hash ? `#${hash}` : ''}`;
});
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center gap-2">
      <div class="text-sm font-medium">
        {{ t('预览') }}
      </div>
    </div>

    <div
      class="h-[calc(100vh-12rem)] max-h-[640px] min-h-[480px] overflow-hidden rounded-lg border bg-background shadow-sm"
    >
      <iframe
        :key="props.refreshKey ?? props.url"
        :src="previewUrl"
        :title="t('预览')"
        class="h-full w-full border-0 bg-background"
        loading="lazy"
        referrerpolicy="same-origin"
      ></iframe>
    </div>
  </div>
</template>
