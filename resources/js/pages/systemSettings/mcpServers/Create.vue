<!--
  文件说明：MCP 服务创建页面，承接服务名称、端点、认证方式和连接测试。
  消费后端 ShowCreateMcpServerPagePropsData。
-->
<script setup lang="ts">
import Mcp from '@/actions/App/Actions/Mcp';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type { ShowCreateMcpServerPagePropsData } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import McpServerFormPanel from './McpServerFormPanel.vue';

const props = defineProps<ShowCreateMcpServerPagePropsData>();
const { t } = useI18n();
</script>

<template>
  <AppLayout>
    <Head :title="t('添加 MCP 服务')" />

    <SystemSettingsLayout>
      <McpServerFormPanel
        mode="create"
        :transport-options="props.transport_options"
        :return-href="Mcp.ShowSystemMcpServersAction.url()"
      />
    </SystemSettingsLayout>
  </AppLayout>
</template>
