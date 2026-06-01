<!--
  文件说明：工作区 AI 供应商「新增」弹窗。先从品牌目录选品牌，再填展示名称和凭据一步创建。
  消费 ShowAiProviderPagePropsData.brandOptions，提交到 CreateAiProviderAction。
-->
<script setup lang="ts">
import AiProvider from '@/actions/App/Actions/AiProvider';
import InputError from '@/components/common/InputError.vue';
import AiProviderIcon from '@/components/icons/AiProviderIcon.vue';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import { useRequiredWorkspace } from '@/composables/useWorkspace';
import type { BrandOptionData } from '@/types/generated';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type CredentialField = {
  field: string;
  label: string;
  type?: 'text' | 'password' | 'url' | 'select';
  required?: boolean;
  secret?: boolean;
  default?: string | null;
  placeholder?: string | null;
};

type CreateProviderForm = {
  brand: string;
  name: string;
  configuration: Record<string, string>;
};

const props = defineProps<{
  open: boolean;
  brandOptions: BrandOptionData[];
}>();

const emit = defineEmits<{
  'update:open': [value: boolean];
}>();

const { t } = useI18n();
const workspace = useRequiredWorkspace();

const selectedBrand = ref<BrandOptionData | null>(null);

const form = useForm<CreateProviderForm>({
  brand: '',
  name: '',
  configuration: {},
});

const brandCredentialFields = computed<CredentialField[]>(
  () => (selectedBrand.value?.credential_fields as CredentialField[]) ?? [],
);

const visibleCredentialFields = computed<CredentialField[]>(() =>
  brandCredentialFields.value.filter(
    (field) => selectedBrand.value?.is_custom || field.field !== 'base_uri',
  ),
);

// 弹窗关闭时回到品牌选择初始态，避免下次打开残留上次的品牌与输入
watch(
  () => props.open,
  (open) => {
    if (!open) {
      selectedBrand.value = null;
      form.reset();
      form.clearErrors();
    }
  },
);

const selectBrand = (brand: BrandOptionData) => {
  selectedBrand.value = brand;

  const configuration: Record<string, string> = {};
  for (const field of (brand.credential_fields as CredentialField[]) ?? []) {
    configuration[field.field] = (field.default as string | null) ?? '';
  }

  form.defaults({ brand: brand.brand, name: '', configuration });
  form.reset();
  form.clearErrors();
};

const backToBrandList = () => {
  selectedBrand.value = null;
  form.clearErrors();
};

const fieldError = (fieldName: string): string | undefined =>
  form.errors[`configuration.${fieldName}`];

const setConfigurationValue = (fieldName: string, value: unknown): void => {
  form.configuration[fieldName] =
    typeof value === 'string' || typeof value === 'number' ? String(value) : '';
};

const submit = () => {
  if (!selectedBrand.value) {
    return;
  }

  form.post(AiProvider.CreateAiProviderAction.url(workspace.value.slug), {
    preserveScroll: true,
    onSuccess: () => {
      emit('update:open', false);
    },
  });
};
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ t('添加供应商') }}</DialogTitle>
        <DialogDescription>
          {{
            selectedBrand ? t('填写凭据后即可使用') : t('选择一个供应商品牌')
          }}
        </DialogDescription>
      </DialogHeader>

      <!-- 第一步：品牌目录 -->
      <div v-if="!selectedBrand" class="grid grid-cols-1 gap-2">
        <button
          v-for="brand in brandOptions"
          :key="brand.brand"
          type="button"
          class="flex items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:bg-muted"
          @click="selectBrand(brand)"
        >
          <AiProviderIcon
            :icon="brand.icon"
            class="h-8 w-8 shrink-0 rounded-md bg-muted p-1.5"
          >
            {{ brand.label.charAt(0).toUpperCase() }}
          </AiProviderIcon>
          <span class="min-w-0 flex-1 truncate text-sm font-medium">
            {{ brand.label }}
          </span>
        </button>
      </div>

      <!-- 第二步：填凭据 -->
      <form v-else class="space-y-4" @submit.prevent="submit">
        <button
          type="button"
          class="flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
          @click="backToBrandList"
        >
          <ArrowLeft class="h-3.5 w-3.5" />
          {{ t('返回选择品牌') }}
        </button>

        <div class="flex items-center gap-3">
          <AiProviderIcon
            :icon="selectedBrand.icon"
            class="h-8 w-8 shrink-0 rounded-md bg-muted p-1.5"
          >
            {{ selectedBrand.label.charAt(0).toUpperCase() }}
          </AiProviderIcon>
          <span class="text-sm font-semibold">{{ selectedBrand.label }}</span>
        </div>

        <div class="grid gap-2">
          <Label for="provider-name" required>{{ t('显示名称') }}</Label>
          <Input
            id="provider-name"
            v-model="form.name"
            autocomplete="off"
            class="w-full"
            required
          />
          <InputError :message="form.errors.name" />
        </div>

        <div
          v-for="field in visibleCredentialFields"
          :key="field.field"
          class="grid gap-2"
        >
          <Label :for="`field-${field.field}`" :required="field.required">
            {{ field.label }}
          </Label>
          <Input
            :id="`field-${field.field}`"
            :model-value="form.configuration[field.field] ?? ''"
            :type="
              field.secret ? 'password' : field.type === 'url' ? 'url' : 'text'
            "
            :placeholder="field.placeholder ?? ''"
            autocomplete="off"
            class="w-full"
            @update:model-value="
              (value) => setConfigurationValue(field.field, value)
            "
          />
          <InputError :message="fieldError(field.field)" />
        </div>

        <DialogFooter class="gap-2">
          <Button
            type="button"
            variant="secondary"
            :disabled="form.processing"
            @click="emit('update:open', false)"
          >
            {{ t('取消') }}
          </Button>
          <Button type="submit" :disabled="form.processing">
            <LoaderCircle
              v-if="form.processing"
              class="mr-2 h-4 w-4 animate-spin"
            />
            {{ t('添加') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
