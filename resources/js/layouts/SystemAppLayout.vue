<!--
  文件说明：前端页面布局，连接页面内容与后台应用外壳。
-->
<script setup lang="ts">
import AppLayout from '@/layouts/app/SystemSidebarLayout.vue';
</script>

<template>
  <AppLayout>
    <slot />
  </AppLayout>
</template>
