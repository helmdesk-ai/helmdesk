<!--
  文件说明：系统存储设置页面。把"启用对象存储 + 当前配置"合并为一个统一的
  "当前生效目标"列表，并在操作列中显式切换当前使用的存储目标。
-->
<script setup lang="ts">
import UpdateStorageSettingAction from '@/actions/App/Actions/StorageSetting/UpdateStorageSettingAction';
import ConfirmDeleteDialog from '@/components/common/ConfirmDeleteDialog.vue';
import HeadingSmall from '@/components/common/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import SystemAppLayout from '@/layouts/SystemAppLayout.vue';
import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue';
import storageProfile from '@/routes/admin/storage/profiles';
import type {
  FormStorageSettingData,
  ShowGetStorageSettingPagePropsData,
  StorageProfileData,
} from '@/types/generated';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MoreHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<ShowGetStorageSettingPagePropsData>();
const { t } = useI18n();

const settingsForm = useForm<FormStorageSettingData>({
  enabled: props.settings.enabled,
  current_profile_id: props.settings.current_profile_id,
});

const actionForm = useForm({});
const checkingProfileId = ref<string | null>(null);
const deletingProfile = ref<StorageProfileData | null>(null);

const isLocalActive = computed(() => !props.settings.enabled);

const isProfileActive = (profile: StorageProfileData): boolean =>
  props.settings.enabled && props.settings.current_profile_id === profile.id;

const providerLabel = (profile: StorageProfileData): string =>
  profile.provider?.label ?? t('本地存储');

const persistSettings = () => {
  settingsForm.put(UpdateStorageSettingAction.url(), {
    preserveScroll: true,
  });
};

const selectLocal = () => {
  if (isLocalActive.value || settingsForm.processing) {
    return;
  }
  settingsForm.enabled = false;
  settingsForm.current_profile_id = null;
  persistSettings();
};

const selectProfile = (profile: StorageProfileData) => {
  if (isProfileActive(profile) || settingsForm.processing) {
    return;
  }
  settingsForm.enabled = true;
  settingsForm.current_profile_id = profile.id;
  persistSettings();
};

const checkProfile = (profile: StorageProfileData) => {
  if (checkingProfileId.value) {
    return;
  }
  checkingProfileId.value = profile.id;
  actionForm.put(storageProfile.check.url(profile.id), {
    preserveScroll: true,
    onFinish: () => {
      checkingProfileId.value = null;
    },
  });
};

const requestDelete = (profile: StorageProfileData) => {
  deletingProfile.value = profile;
};

const cancelDelete = () => {
  deletingProfile.value = null;
};

const confirmDelete = () => {
  const target = deletingProfile.value;
  if (!target || actionForm.processing) {
    return;
  }

  actionForm.delete(storageProfile.destroy.url(target.id), {
    preserveScroll: true,
    onSuccess: () => {
      deletingProfile.value = null;
    },
  });
};
</script>

<template>
  <SystemAppLayout>
    <Head :title="t('存储设置')" />
    <SystemSettingsLayout>
      <div class="mx-auto w-full max-w-none space-y-6">
        <div class="flex items-start justify-between gap-4">
          <HeadingSmall
            :title="t('存储设置')"
            :description="
              t('选择当前生效的文件存储目标；在操作列中切换后立即生效。')
            "
          />
          <Button as-child>
            <Link :href="storageProfile.create.url()">
              {{ t('新增配置') }}
            </Link>
          </Button>
        </div>

        <div class="rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr>
                  <th class="px-4 py-3 text-left font-medium">
                    {{ t('名称') }}
                  </th>
                  <th class="px-4 py-3 text-left font-medium">
                    {{ t('存储供应商') }}
                  </th>
                  <th class="px-4 py-3 text-left font-medium">
                    {{ t('区域') }}
                  </th>
                  <th class="px-4 py-3 text-left font-medium">
                    {{ t('Endpoint 地址') }}
                  </th>
                  <th class="px-4 py-3 text-left font-medium">
                    {{ t('Bucket') }}
                  </th>
                  <th class="px-4 py-3 text-right font-medium">
                    {{ t('操作') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  class="border-b transition-colors hover:bg-muted/30"
                  :class="{ 'bg-primary/5': isLocalActive }"
                >
                  <td class="px-4 py-3 align-middle">
                    <div class="font-medium">{{ t('本地存储') }}</div>
                  </td>
                  <td class="px-4 py-3 align-middle">
                    <Badge variant="secondary">{{ t('本地磁盘') }}</Badge>
                  </td>
                  <td class="px-4 py-3 align-middle text-muted-foreground">
                    {{ t('当前服务器') }}
                  </td>
                  <td class="px-4 py-3 align-middle text-muted-foreground">
                    {{ t('应用本地文件系统') }}
                  </td>
                  <td class="px-4 py-3 align-middle text-muted-foreground">
                    {{ t('本地 private 目录') }}
                  </td>
                  <td class="px-4 py-3 text-right align-middle">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      :disabled="isLocalActive || settingsForm.processing"
                      @click="selectLocal"
                    >
                      {{ isLocalActive ? t('使用中') : t('切换') }}
                    </Button>
                  </td>
                </tr>

                <tr
                  v-for="p in props.profiles"
                  :key="p.id"
                  class="border-b transition-colors last:border-b-0 hover:bg-muted/30"
                  :class="{ 'bg-primary/5': isProfileActive(p) }"
                >
                  <td class="px-4 py-3 align-middle">
                    <div class="font-medium">{{ p.name }}</div>
                    <div
                      v-if="p.url"
                      class="mt-1 max-w-72 truncate text-xs text-muted-foreground"
                      :title="p.url"
                    >
                      {{ p.url }}
                    </div>
                  </td>
                  <td class="px-4 py-3 align-middle">
                    <Badge variant="secondary">
                      {{ providerLabel(p) }}
                    </Badge>
                  </td>
                  <td class="px-4 py-3 align-middle text-muted-foreground">
                    {{ p.region || '-' }}
                  </td>
                  <td
                    class="max-w-72 px-4 py-3 align-middle font-mono text-xs text-muted-foreground"
                  >
                    <div class="truncate" :title="p.endpoint || '-'">
                      {{ p.endpoint || '-' }}
                    </div>
                  </td>
                  <td
                    class="max-w-48 truncate px-4 py-3 align-middle font-mono text-xs text-muted-foreground"
                    :title="p.bucket || '-'"
                  >
                    {{ p.bucket || '-' }}
                  </td>
                  <td class="px-4 py-3 text-right align-middle" @click.stop>
                    <div class="inline-flex items-center justify-end gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="
                          isProfileActive(p) || settingsForm.processing
                        "
                        @click="selectProfile(p)"
                      >
                        {{ isProfileActive(p) ? t('使用中') : t('切换') }}
                      </Button>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="
                          actionForm.processing && checkingProfileId === p.id
                        "
                        @click="checkProfile(p)"
                      >
                        {{
                          checkingProfileId === p.id
                            ? t('检测中...')
                            : t('检测连接')
                        }}
                      </Button>
                      <Button variant="outline" size="sm" as-child>
                        <Link :href="storageProfile.edit.url(p.id)">
                          {{ t('编辑') }}
                        </Link>
                      </Button>
                      <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :aria-label="t('更多操作')"
                          >
                            <MoreHorizontal class="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-36">
                          <DropdownMenuItem
                            class="text-destructive focus:text-destructive"
                            @select="requestDelete(p)"
                          >
                            {{ t('删除') }}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <ConfirmDeleteDialog
          :open="deletingProfile !== null"
          :title="t('确认删除存储配置？')"
          :detail-title="deletingProfile?.name"
          :detail-description="
            t('删除后该配置不再可用；如果有附件还引用着该配置，删除会被拒绝。')
          "
          :processing="actionForm.processing"
          @update:open="(open: boolean) => !open && cancelDelete()"
          @confirm="confirmDelete"
        />
      </div>
    </SystemSettingsLayout>
  </SystemAppLayout>
</template>
