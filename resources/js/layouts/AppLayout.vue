<!--
  文件说明：前端页面布局，连接页面内容与后台应用外壳。
-->
<script setup lang="ts">
import AppLayout from '@/layouts/app/SystemSidebarLayout.vue';

interface Props {
  hideHeader?: boolean;
}

withDefaults(defineProps<Props>(), {
  hideHeader: false,
});
</script>

<template>
  <AppLayout :hide-header="hideHeader">
    <slot />
  </AppLayout>
</template>
