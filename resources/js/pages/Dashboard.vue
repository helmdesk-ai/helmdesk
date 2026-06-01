<!--
  文件说明：仪表板页面，作为当前工作区首页承接后续工作区概览内容。
-->
<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

const { t } = useI18n();
</script>

<template>
  <AppLayout hide-header>
    <Head :title="t('仪表板')" />
    <div class="min-h-[calc(100svh-1rem)]"></div>
  </AppLayout>
</template>
