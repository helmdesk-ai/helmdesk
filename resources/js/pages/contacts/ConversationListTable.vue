<!--
  文件说明：联系人模块页面，承接联系人列表、详情抽屉、会话记录和筛选交互。
-->
<script setup lang="ts">
import PaginationNavigator from '@/components/common/PaginationNavigator.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { useI18n } from '@/composables/useI18n';
import { useVisitorDisplay } from '@/composables/useVisitorDisplay';
import type {
  ListConversationItemData,
  SimplePaginationData,
} from '@/types/generated';

const { t } = useI18n();
const { formatDateTime } = useDateTime();
const { formatVisitorName } = useVisitorDisplay();

/*
 * 列表表格 + 分页行。父组件负责构造分页 URL，
 * 这里保持纯展示，页码和上下页跳转都由父组件提供 URL 生成函数。
 */
const props = defineProps<{
  conversations: ListConversationItemData[];
  pagination: SimplePaginationData;
  pageUrl: (page: number) => string;
}>();

defineEmits<{
  openConversation: [conversation: ListConversationItemData];
}>();

const secondaryStatusLabel = (conversation: ListConversationItemData) => {
  if (conversation.status.value === 'closed') {
    return null;
  }

  if (conversation.waiting_for_visitor_reply_label) {
    return conversation.waiting_for_visitor_reply_label;
  }

  return conversation.inbox_status.label;
};
</script>

<template>
  <div class="rounded-lg border">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="border-b bg-muted/30 text-muted-foreground">
          <tr class="text-left">
            <th class="px-4 py-3">{{ t('主题') }}</th>
            <th class="px-4 py-3">{{ t('联系人') }}</th>
            <th class="px-4 py-3">{{ t('状态') }}</th>
            <th class="px-4 py-3">{{ t('最后消息') }}</th>
            <th class="px-4 py-3 text-right">{{ t('操作') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="conversationItem in props.conversations"
            :key="conversationItem.id"
            class="border-t bg-background"
          >
            <td class="px-4 py-3">
              <div class="space-y-1">
                <div class="font-medium">
                  {{ conversationItem.subject || t('无主题会话') }}
                </div>
                <div class="line-clamp-2 text-xs text-muted-foreground">
                  {{
                    conversationItem.display_last_message_preview ||
                    conversationItem.last_message_preview ||
                    conversationItem.summary ||
                    '-'
                  }}
                </div>
              </div>
            </td>
            <td class="px-4 py-3">
              <div class="space-y-1">
                <div>
                  {{
                    formatVisitorName(
                      conversationItem.contact_name,
                      conversationItem.contact_id,
                    )
                  }}
                </div>
                <div class="text-xs text-muted-foreground">
                  {{
                    conversationItem.contact_primary_email ||
                    conversationItem.contact_primary_phone ||
                    '-'
                  }}
                </div>
              </div>
            </td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap gap-1">
                <Badge variant="secondary">
                  {{ conversationItem.status.label }}
                </Badge>
                <Badge
                  v-if="secondaryStatusLabel(conversationItem)"
                  variant="outline"
                >
                  {{ secondaryStatusLabel(conversationItem) }}
                </Badge>
              </div>
            </td>
            <td class="px-4 py-3 text-muted-foreground">
              {{
                conversationItem.last_message_at
                  ? formatDateTime(conversationItem.last_message_at)
                  : '-'
              }}
            </td>
            <td class="px-4 py-3">
              <div class="flex justify-end">
                <Button
                  variant="outline"
                  size="sm"
                  @click="$emit('openConversation', conversationItem)"
                >
                  {{ t('查看') }}
                </Button>
              </div>
            </td>
          </tr>

          <tr v-if="props.conversations.length === 0">
            <td class="px-4 py-8 text-center text-muted-foreground" colspan="5">
              {{ t('暂无会话记录') }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="props.pagination.last_page > 1" class="border-t px-4 py-3">
      <PaginationNavigator
        :pagination="props.pagination"
        :page-url="props.pageUrl"
      />
    </div>
  </div>
</template>
