<!--
  文件说明：认证页布局，承接登录、注册和密码相关页面的外层结构。
-->
<script setup lang="ts">
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { home } from '@/routes';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
  title?: string;
  description?: string;
}>();

const page = usePage();
const logo = computed(() => page.props.generalSettings.logo_url);
const systemName = computed(() => page.props.generalSettings.name);
</script>

<template>
  <div
    class="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10"
  >
    <div class="flex w-full max-w-md flex-col gap-6">
      <Link
        :href="home()"
        class="flex items-center gap-2 self-center font-medium"
      >
        <div class="flex h-9 w-9 items-center justify-center">
          <img
            :src="logo"
            :alt="systemName + ' Logo'"
            class="size-9 object-contain"
          />
        </div>
      </Link>

      <div class="flex flex-col gap-6">
        <Card class="rounded-xl">
          <CardHeader class="px-10 pt-8 pb-0 text-center">
            <CardTitle class="text-xl">{{ title }}</CardTitle>
            <CardDescription>
              {{ description }}
            </CardDescription>
          </CardHeader>
          <CardContent class="px-10 py-8">
            <slot />
          </CardContent>
        </Card>
      </div>
    </div>
  </div>
</template>
