<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import type { SelectItemProps } from 'reka-ui';
import { SelectItem, SelectItemIndicator, SelectItemText } from 'reka-ui';
import { reactiveOmit } from '@vueuse/core';
import { Check } from '@lucide/vue';
import { cn } from '@/lib/utils';

const props = defineProps<
    SelectItemProps & {
        class?: HTMLAttributes['class'];
        // 隐藏选中态左侧的对钩图标，并去掉为其预留的左内边距
        hideIndicator?: boolean;
    }
>();

// hideIndicator / class 仅用于本组件渲染，不向 reka-ui 原语透传，避免落到 DOM 上
const delegatedProps = reactiveOmit(props, 'hideIndicator', 'class');
</script>

<template>
    <SelectItem
        v-bind="delegatedProps"
        :class="
            cn(
                'relative flex w-full cursor-default select-none items-center rounded-sm py-1.5 pr-2 text-sm outline-none',
                props.hideIndicator ? 'pl-2' : 'pl-8',
                'focus:bg-accent focus:text-accent-foreground',
                'data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
                props.class,
            )
        "
    >
        <span v-if="!props.hideIndicator" class="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
            <SelectItemIndicator>
                <Check class="h-4 w-4" />
            </SelectItemIndicator>
        </span>

        <SelectItemText>
            <slot />
        </SelectItemText>
    </SelectItem>
</template>
