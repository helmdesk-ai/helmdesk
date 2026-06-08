<!--
  文件说明：认证页布局，承接登录、注册和密码相关页面的外层结构。
-->
<script setup lang="ts">
import AppLogoIcon from '@/components/common/AppLogoIcon.vue';
import { home } from '@/routes';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const name = page.props.name;
const quote = page.props.quote;
const logo = computed(() => page.props.generalSettings.logo_url);
// 未上传自定义 Logo 时回退到矢量品牌组件；此处面板恒为深色，故跟随 text-white
const isDefaultLogo = computed(() => !page.props.generalSettings.logo_id);

defineProps<{
  title?: string;
  description?: string;
}>();
</script>

<template>
  <div
    class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
  >
    <div
      class="relative hidden h-full flex-col bg-muted p-10 text-white lg:flex dark:border-r"
    >
      <div class="absolute inset-0 bg-zinc-900" />
      <Link
        :href="home()"
        class="relative z-20 flex items-center text-lg font-medium"
      >
        <AppLogoIcon v-if="isDefaultLogo" class="mr-2 size-8 text-white" />
        <img
          v-else
          :src="logo"
          :alt="name + ' Logo'"
          class="mr-2 size-8 object-contain"
        />
        {{ name }}
      </Link>
      <div v-if="quote" class="relative z-20 mt-auto">
        <blockquote class="space-y-2">
          <p class="text-lg">&ldquo;{{ quote.message }}&rdquo;</p>
          <footer class="text-sm text-neutral-300">
            {{ quote.author }}
          </footer>
        </blockquote>
      </div>
    </div>
    <div class="lg:p-8">
      <div
        class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
      >
        <div class="flex flex-col space-y-2 text-center">
          <h1 class="text-xl font-medium tracking-tight" v-if="title">
            {{ title }}
          </h1>
          <p class="text-sm text-muted-foreground" v-if="description">
            {{ description }}
          </p>
        </div>
        <slot />
      </div>
    </div>
  </div>
</template>
