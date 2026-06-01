<!--
  文件说明：前端页面布局，连接页面内容与后台应用外壳。
-->
<script setup lang="ts">
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

defineProps<{
  title?: string;
  description?: string;
}>();
</script>

<template>
  <AuthLayout :title="title" :description="description">
    <slot />
  </AuthLayout>
</template>
