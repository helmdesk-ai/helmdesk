<!--
  文件说明：后台应用布局片段，承接侧边栏、顶部状态和系统上下文。
-->
<script setup lang="ts">
import { useSidebar } from '@/components/ui/sidebar';

const sidebar = useSidebar();
</script>

<template>
  <slot v-bind="sidebar" />
</template>
