<!--
  文件说明：网站渠道自定义传参标签页，承接外部参数到访客资料的自动写入规则。
-->
<script setup lang="ts">
import Web from '@/actions/App/Actions/Channel/Web';
import FormActions from '@/components/common/FormActions.vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import type {
  WebChannelData,
  WebChannelFormOptionsData,
  WebChannelQueryParamMappingData,
} from '@/types/generated';
import { Form, router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

type ParamTarget = WebChannelQueryParamMappingData['target'];
type ParamWriteMode = WebChannelQueryParamMappingData['write_mode'];

type MappingRow = {
  param_name: string;
  target: ParamTarget;
  target_key: string;
  write_mode: ParamWriteMode;
};

const props = defineProps<{
  channel: WebChannelData;
  formOptions: WebChannelFormOptionsData;
}>();

const { t } = useI18n();

const maskedSecret = computed(() => props.channel.user_token_secret_masked);
const currentSecret = computed(() => props.channel.user_token_secret);
const secretCopied = ref(false);
const isRegeneratingSecret = ref(false);

function regenerateSecret(): void {
  router.post(
    Web.RegenerateWebChannelUserTokenSecretAction.url({
      channel: props.channel.id,
    }),
    {},
    {
      preserveScroll: true,
      onStart: () => {
        isRegeneratingSecret.value = true;
      },
      onFinish: () => {
        isRegeneratingSecret.value = false;
      },
    },
  );
}

async function copySecret(): Promise<void> {
  if (!currentSecret.value) {
    return;
  }

  await navigator.clipboard.writeText(currentSecret.value);
  secretCopied.value = true;
  window.setTimeout(() => {
    secretCopied.value = false;
  }, 2000);
}

const mappings = ref<MappingRow[]>(
  (props.channel.query_param_mappings ?? []).map((mapping) => ({
    param_name: mapping.param_name,
    target: mapping.target,
    target_key: mapping.target_key ?? '',
    write_mode: mapping.write_mode,
  })),
);
const addMappingOpen = ref(false);
const draftMapping = ref<MappingRow>(newMappingRow());

const targetOptions = computed(
  () => props.formOptions.query_param_target_options,
);
const writeModeOptions = computed(
  () => props.formOptions.query_param_write_mode_options,
);
const writableAttributeOptions = computed(
  () => props.formOptions.writable_attribute_definition_options,
);

function targetRequiresKey(target: ParamTarget): boolean {
  return target === 'attribute' || target === 'tag';
}

function targetKeyLabel(target: ParamTarget): string {
  if (target === 'attribute') {
    return t('属性 Key');
  }
  if (target === 'tag') {
    return t('标签模板');
  }
  return t('目标键');
}

function targetKeyHint(target: ParamTarget): string {
  if (target === 'attribute') {
    return t(
      '选择已开启 API 写入的自定义属性。属性类型为单选时，参数值需匹配选项 code。',
    );
  }
  if (target === 'tag') {
    return t(
      '模板支持 {value} 占位（仅允许字母/数字/下划线/连字符 1~40 位）；无占位时使用模板字面量。',
    );
  }
  return '';
}

function newMappingRow(): MappingRow {
  return {
    param_name: '',
    target: 'tag' as ParamTarget,
    target_key: '',
    write_mode: 'only_if_empty' as ParamWriteMode,
  };
}

function resetDraftMapping(): void {
  draftMapping.value = newMappingRow();
}

function addMapping(): void {
  mappings.value.push({ ...draftMapping.value });
  resetDraftMapping();
  addMappingOpen.value = false;
}

function removeMapping(index: number): void {
  mappings.value.splice(index, 1);
}

function onTargetChange(index: number, value: unknown): void {
  if (typeof value !== 'string') {
    return;
  }
  mappings.value[index].target = value as ParamTarget;
  if (!targetRequiresKey(mappings.value[index].target)) {
    mappings.value[index].target_key = '';
  }
}

function onDraftTargetChange(value: unknown): void {
  if (typeof value !== 'string') {
    return;
  }
  draftMapping.value.target = value as ParamTarget;
  if (!targetRequiresKey(draftMapping.value.target)) {
    draftMapping.value.target_key = '';
  }
}
</script>

<template>
  <Form
    :action="
      Web.UpdateWebChannelEmbedAction.url({
        channel: props.channel.id,
      })
    "
    method="put"
    class="space-y-8"
  >
    <template #default="{ errors, processing }">
      <template v-for="(mapping, index) in mappings" :key="`mapping-${index}`">
        <input
          type="hidden"
          :name="`query_param_mappings[${index}][param_name]`"
          :value="mapping.param_name"
        />
        <input
          type="hidden"
          :name="`query_param_mappings[${index}][target]`"
          :value="mapping.target"
        />
        <input
          type="hidden"
          :name="`query_param_mappings[${index}][target_key]`"
          :value="mapping.target_key"
        />
        <!--
          信任级别当前统一为 always，暂不暴露 SignedOnly 选项。
          后端 WebChannelParamTrust 枚举已完整支持两种模式，后续如需
          对敏感字段（email/phone/external_id）启用签名校验再放开 UI。
        -->
        <input
          type="hidden"
          :name="`query_param_mappings[${index}][trust]`"
          value="always"
        />
        <input
          type="hidden"
          :name="`query_param_mappings[${index}][write_mode]`"
          :value="mapping.write_mode"
        />
      </template>

      <section class="space-y-3">
        <div>
          <Label>{{ t('登录用户身份签名') }}</Label>
          <p class="mt-1 text-sm text-muted-foreground">
            {{
              t(
                '配置签名密钥后，你的业务后端可签发 JWT，让已登录用户以可信身份接入客服，防止他人伪造身份。下方的明文参数映射只适合来源、活动等非敏感信息。',
              )
            }}
          </p>
        </div>

        <div
          class="flex flex-wrap items-center gap-3 rounded-md border bg-muted/30 px-3 py-2 text-sm"
        >
          <span
            v-if="maskedSecret"
            class="font-mono text-sm break-all text-foreground"
          >
            {{ maskedSecret }}
          </span>
          <span v-else class="text-muted-foreground">
            {{ t('未生成密钥') }}
          </span>
          <div class="ml-auto flex items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              :disabled="!currentSecret"
              @click="copySecret"
            >
              {{ secretCopied ? t('已复制') : t('复制') }}
            </Button>
            <Button
              v-if="!currentSecret"
              type="button"
              variant="outline"
              size="sm"
              :disabled="isRegeneratingSecret"
              @click="regenerateSecret"
            >
              {{ isRegeneratingSecret ? t('生成中...') : t('生成密钥') }}
            </Button>
            <Dialog v-else>
              <DialogTrigger as-child>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  :disabled="isRegeneratingSecret"
                >
                  {{ t('重置密钥') }}
                </Button>
              </DialogTrigger>
              <DialogContent class="sm:max-w-md">
                <DialogHeader>
                  <DialogTitle>{{ t('确认重置签名密钥？') }}</DialogTitle>
                  <DialogDescription>
                    {{
                      t(
                        '重置后现有 token 将立即失效，使用当前密钥签发的访客身份无法再通过校验。系统会立即生成新密钥。',
                      )
                    }}
                  </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                  <DialogClose as-child>
                    <Button type="button" variant="outline">
                      {{ t('取消') }}
                    </Button>
                  </DialogClose>
                  <DialogClose as-child>
                    <Button
                      type="button"
                      :disabled="isRegeneratingSecret"
                      @click="regenerateSecret"
                    >
                      {{
                        isRegeneratingSecret ? t('重置中...') : t('确认重置')
                      }}
                    </Button>
                  </DialogClose>
                </DialogFooter>
              </DialogContent>
            </Dialog>
          </div>
        </div>
      </section>

      <section class="space-y-3 border-t pt-8">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm text-muted-foreground">
              {{
                t(
                  '把聊天链接 URL 参数或网站嵌入配置参数按映射写入联系人字段、自定义属性或标签。属性必须开启 API 写入，适合记录来源、活动和入口等公开上下文。',
                )
              }}
            </p>
          </div>
          <Dialog
            v-model:open="addMappingOpen"
            @update:open="resetDraftMapping"
          >
            <DialogTrigger as-child>
              <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="processing || mappings.length >= 32"
              >
                {{ t('新增映射') }}
              </Button>
            </DialogTrigger>
            <DialogContent class="sm:max-w-lg">
              <DialogHeader>
                <DialogTitle>{{ t('新增映射') }}</DialogTitle>
                <DialogDescription>
                  {{
                    t('配置一个外部参数如何写入联系人资料，保存页面后生效。')
                  }}
                </DialogDescription>
              </DialogHeader>

              <form class="space-y-4" @submit.prevent="addMapping">
                <div class="grid gap-2">
                  <Label for="draft_param_name" required>
                    {{ t('参数名') }}
                  </Label>
                  <Input
                    id="draft_param_name"
                    v-model="draftMapping.param_name"
                    required
                  />
                </div>

                <div class="grid gap-2">
                  <Label>{{ t('写入目标') }}</Label>
                  <Select
                    :model-value="draftMapping.target"
                    @update:model-value="onDraftTargetChange"
                  >
                    <SelectTrigger class="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem
                        v-for="option in targetOptions"
                        :key="option.value"
                        :value="option.value"
                      >
                        {{ option.label }}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div
                  v-if="targetRequiresKey(draftMapping.target)"
                  class="grid gap-2"
                >
                  <Label for="draft_target_key" required>
                    {{ targetKeyLabel(draftMapping.target) }}
                  </Label>
                  <Select
                    v-if="draftMapping.target === 'attribute'"
                    v-model="draftMapping.target_key"
                    :disabled="writableAttributeOptions.length === 0"
                  >
                    <SelectTrigger class="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem
                        v-for="option in writableAttributeOptions"
                        :key="option.value"
                        :value="option.value"
                      >
                        {{ option.label }} · {{ option.type_label }}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                  <Input
                    v-else
                    id="draft_target_key"
                    v-model="draftMapping.target_key"
                    required
                  />
                  <p class="text-xs text-muted-foreground">
                    {{ targetKeyHint(draftMapping.target) }}
                  </p>
                </div>

                <div class="grid gap-2">
                  <Label>{{ t('写入模式') }}</Label>
                  <Select v-model="draftMapping.write_mode">
                    <SelectTrigger class="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem
                        v-for="option in writeModeOptions"
                        :key="option.value"
                        :value="option.value"
                      >
                        {{ option.label }}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <DialogFooter>
                  <DialogClose as-child>
                    <Button type="button" variant="outline">
                      {{ t('取消') }}
                    </Button>
                  </DialogClose>
                  <Button type="submit">
                    {{ t('添加') }}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </div>

        <div class="min-w-0 rounded-lg border">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b bg-muted/30 text-muted-foreground">
                <tr class="text-left">
                  <th class="min-w-48 px-4 py-3">
                    {{ t('参数名') }}
                  </th>
                  <th class="min-w-44 px-4 py-3">
                    {{ t('写入目标') }}
                  </th>
                  <th class="min-w-64 px-4 py-3">
                    {{ t('目标键') }}
                  </th>
                  <th class="min-w-44 px-4 py-3">
                    {{ t('写入模式') }}
                  </th>
                  <th class="w-20 px-4 py-3 text-right whitespace-nowrap">
                    {{ t('操作') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(mapping, index) in mappings"
                  :key="`row-${index}`"
                  class="border-t bg-background"
                >
                  <td class="px-4 py-3 align-top">
                    <div class="grid gap-1.5">
                      <Label :for="`param_name_${index}`" class="sr-only">
                        {{ t('参数名') }}
                      </Label>
                      <Input
                        :id="`param_name_${index}`"
                        v-model="mapping.param_name"
                      />
                      <InputError
                        :message="
                          errors[`query_param_mappings.${index}.param_name`]
                        "
                      />
                    </div>
                  </td>
                  <td class="px-4 py-3 align-top">
                    <div class="grid gap-1.5">
                      <Label class="sr-only">{{ t('写入目标') }}</Label>
                      <Select
                        :model-value="mapping.target"
                        :disabled="processing"
                        @update:model-value="onTargetChange(index, $event)"
                      >
                        <SelectTrigger class="w-full">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem
                            v-for="option in targetOptions"
                            :key="option.value"
                            :value="option.value"
                          >
                            {{ option.label }}
                          </SelectItem>
                        </SelectContent>
                      </Select>
                      <InputError
                        :message="
                          errors[`query_param_mappings.${index}.target`]
                        "
                      />
                    </div>
                  </td>
                  <td class="px-4 py-3 align-top">
                    <div class="grid gap-1.5">
                      <Label :for="`target_key_${index}`" class="sr-only">
                        {{ targetKeyLabel(mapping.target) }}
                      </Label>
                      <Select
                        v-if="mapping.target === 'attribute'"
                        v-model="mapping.target_key"
                        :disabled="
                          processing || writableAttributeOptions.length === 0
                        "
                      >
                        <SelectTrigger class="w-full">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem
                            v-for="option in writableAttributeOptions"
                            :key="option.value"
                            :value="option.value"
                          >
                            {{ option.label }} · {{ option.type_label }}
                          </SelectItem>
                        </SelectContent>
                      </Select>
                      <Input
                        v-else-if="targetRequiresKey(mapping.target)"
                        :id="`target_key_${index}`"
                        v-model="mapping.target_key"
                      />
                      <span
                        v-else
                        class="flex min-h-9 items-center text-muted-foreground"
                      >
                        -
                      </span>
                      <p
                        v-if="targetRequiresKey(mapping.target)"
                        class="text-xs text-muted-foreground"
                      >
                        {{ targetKeyHint(mapping.target) }}
                      </p>
                      <InputError
                        :message="
                          errors[`query_param_mappings.${index}.target_key`]
                        "
                      />
                    </div>
                  </td>
                  <td class="px-4 py-3 align-top">
                    <div class="grid gap-1.5">
                      <Label class="sr-only">{{ t('写入模式') }}</Label>
                      <Select
                        v-model="mapping.write_mode"
                        :disabled="processing"
                      >
                        <SelectTrigger class="w-full">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem
                            v-for="option in writeModeOptions"
                            :key="option.value"
                            :value="option.value"
                          >
                            {{ option.label }}
                          </SelectItem>
                        </SelectContent>
                      </Select>
                      <InputError
                        :message="
                          errors[`query_param_mappings.${index}.write_mode`]
                        "
                      />
                    </div>
                  </td>
                  <td class="px-4 py-3 align-top whitespace-nowrap">
                    <div class="flex min-h-9 items-center justify-end">
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8"
                        :aria-label="t('删除映射')"
                        :title="t('删除映射')"
                        @click="removeMapping(index)"
                      >
                        <Trash2 class="size-4" />
                      </Button>
                    </div>
                  </td>
                </tr>

                <tr v-if="mappings.length === 0">
                  <td
                    class="px-4 py-8 text-center text-muted-foreground"
                    colspan="5"
                  >
                    {{ t('暂无自定义传参') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <FormActions :submit-label="t('保存')" :processing="processing" />
    </template>
  </Form>
</template>
