<!--
  文件说明：MCP 服务编辑页面，承接服务连接配置保存和连接测试。
  消费后端 ShowEditMcpServerPagePropsData。
-->
<script setup lang="ts">
import Mcp from '@/actions/App/Actions/Mcp';
import AppLayout from '@/layouts/AppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import type { ShowEditMcpServerPagePropsData } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import McpServerFormPanel from './McpServerFormPanel.vue';

const props = defineProps<ShowEditMcpServerPagePropsData>();
const { t } = useI18n();
</script>

<template>
  <AppLayout>
    <Head :title="t('编辑 MCP 服务')" />

    <SystemSettingsLayout>
      <McpServerFormPanel
        mode="edit"
        :server="props.server"
        :transport-options="props.transport_options"
        :return-href="Mcp.ShowSystemMcpServersAction.url()"
      />
    </SystemSettingsLayout>
  </AppLayout>
</template>
