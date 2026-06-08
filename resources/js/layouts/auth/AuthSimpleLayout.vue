<!--
  文件说明：认证页布局，承接登录、注册和密码相关页面的外层结构。
-->
<script setup lang="ts">
import AppLogoIcon from '@/components/common/AppLogoIcon.vue';
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
// 未上传自定义 Logo 时回退到矢量品牌组件，随主题自适应颜色
const isDefaultLogo = computed(() => !page.props.generalSettings.logo_id);
</script>

<template>
  <div
    class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
  >
    <div class="w-full max-w-sm">
      <div class="flex flex-col gap-8">
        <div class="flex flex-col items-center gap-4">
          <Link
            :href="home()"
            class="flex flex-col items-center gap-2 font-medium"
          >
            <div
              class="mb-1 flex h-9 w-9 items-center justify-center rounded-md"
            >
              <AppLogoIcon
                v-if="isDefaultLogo"
                class="size-9 text-foreground"
              />
              <img
                v-else
                :src="logo"
                :alt="systemName + ' Logo'"
                class="size-9 object-contain"
              />
            </div>
            <span class="sr-only">{{ title }}</span>
          </Link>
          <div class="space-y-2 text-center">
            <h1 class="text-xl font-medium">{{ title }}</h1>
            <p class="text-center text-sm text-muted-foreground">
              {{ description }}
            </p>
          </div>
        </div>
        <slot />
      </div>
    </div>
  </div>
</template>
