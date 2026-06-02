<!--
  文件说明：联系人模块顶部分类切换 tab，复用于联系人列表与回收站页面。
-->
<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import workspace from '@/routes/workspace';
import type { ContactListType, EnumOptionData } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

type ContactTabValue = ContactListType | 'trash';

const props = defineProps<{
  current: ContactTabValue;
  listTypeOptions: EnumOptionData[];
}>();

const { t } = useI18n();

interface ContactTab {
  value: ContactTabValue;
  label: string;
  href: string;
}

const tabs = computed<ContactTab[]>(() => [
  ...props.listTypeOptions.map((option) => {
    const value = String(option.value) as ContactListType;

    return {
      value,
      label: option.label,
      href: workspace.contacts.index.url({
        type: value,
      }),
    };
  }),
  {
    value: 'trash',
    label: t('回收站'),
    href: workspace.contacts.trash.url(),
  },
]);
</script>

<template>
  <nav class="-mb-px flex gap-1">
    <Link
      v-for="tab in tabs"
      :key="tab.value"
      :href="tab.href"
      :class="[
        'border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
        tab.value === props.current
          ? 'border-foreground text-foreground'
          : 'border-transparent text-muted-foreground hover:text-foreground',
      ]"
    >
      {{ tab.label }}
    </Link>
  </nav>
</template>
