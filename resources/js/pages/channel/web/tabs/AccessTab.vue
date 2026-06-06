<!--
  文件说明：网站渠道接入方式配置标签页。
  含「网站嵌入」与「聊天链接」两个二级子 Tab：
  - 网站嵌入：展示安装代码、接入指导对话框、嵌入域名白名单。
  - 聊天链接：基础域名只读，仅允许在域名后追加附加 query；可生成对应二维码，保存时持久化 query。
-->
<script setup lang="ts">
import Web from '@/actions/App/Actions/Channel/Web';
import EmbedStatusCard from '@/components/channel/EmbedStatusCard.vue';
import FormActions from '@/components/common/FormActions.vue';
import InputError from '@/components/common/InputError.vue';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import { useUrlTab } from '@/composables/useUrlTab';
import type { WebChannelData } from '@/types/generated';
import { Form } from '@inertiajs/vue3';
import { Check, CircleHelp, Copy } from '@lucide/vue';
import * as QRCode from 'qrcode';
import { computed, ref } from 'vue';

const props = defineProps<{
  channel: WebChannelData;
}>();

const { t } = useI18n();

type AccessSubTab = 'embed' | 'standalone';
// 子 Tab 状态同步到 URL（access_tab 查询参数），刷新或复制链接后仍停留在同一子 Tab。
const accessSubTab = useUrlTab<AccessSubTab>('access_tab', {
  defaultValue: 'embed',
  valid: ['embed', 'standalone'],
});
const subTabs = computed<{ value: AccessSubTab; label: string }[]>(() => [
  { value: 'embed', label: t('网站嵌入') },
  { value: 'standalone', label: t('聊天链接') },
]);

const copiedWidgetCode = ref(false);
const chatLinkQrCodeDataUrl = ref('');

// 聊天链接编辑态：domain 部分固定不允许改，仅暴露 query 部分供编辑。
const standaloneLinkQuery = ref(props.channel.standalone_link_query ?? '');
const normalizedLinkQuery = computed(() =>
  standaloneLinkQuery.value.trim().replace(/^\?+/, ''),
);
const composedChatLink = computed(() =>
  normalizedLinkQuery.value
    ? `${props.channel.standalone_url}?${normalizedLinkQuery.value}`
    : props.channel.standalone_url,
);

const initialAllowedHosts = computed<string[]>(() =>
  Object.values(props.channel.allowed_embed_hosts ?? {}),
);
const allowedHostsText = ref(initialAllowedHosts.value.join('\n'));
const allowedHostsLines = computed<string[]>(() =>
  allowedHostsText.value
    .split(/\r?\n/)
    .map((line: string) => line.trim())
    .filter((line: string) => line.length > 0),
);

const chatLinkParamUrl = computed(
  () => `${props.channel.standalone_url}?utm_source=homepage&campaign=spring`,
);
const chatLinkSignedUrl = computed(
  () => `${props.channel.standalone_url}?user_token=<在你后端签发的 JWT>`,
);
const scriptCloseTag = '</' + 'script>';
const widgetAdvancedSnippet = computed(
  () => `${props.channel.widget_snippet}
<script>
window.HelmDeskWidget = {
  // 登录用户：你的后端用签名密钥签发的 JWT，作为可信身份接入
  user_token: '<在你后端签发的 JWT>',
  // 非敏感补充信息：按「自定义传参」映射进访客资料
  visitor: {
    external_id: 'user_123',
    email: 'user@example.com',
    phone: '+8613800138000',
    name: '张三',
  },
  params: {
    utm_source: 'homepage',
    campaign: 'spring',
  },
};
${scriptCloseTag}`,
);
const declarativeTriggerSnippet = `<button data-helmdesk-open>${t('联系客服')}</button>`;

let qrCodeRequestId = 0;
const qrCodeGenerating = ref(false);

async function generateQrCode(): Promise<void> {
  const requestId = ++qrCodeRequestId;
  qrCodeGenerating.value = true;

  try {
    const dataUrl = await QRCode.toDataURL(composedChatLink.value, {
      width: 160,
      margin: 1,
      errorCorrectionLevel: 'M',
      color: {
        dark: '#111827',
        light: '#FFFFFF',
      },
    });

    if (requestId === qrCodeRequestId) {
      chatLinkQrCodeDataUrl.value = dataUrl;
    }
  } catch {
    if (requestId === qrCodeRequestId) {
      chatLinkQrCodeDataUrl.value = '';
    }
  } finally {
    if (requestId === qrCodeRequestId) {
      qrCodeGenerating.value = false;
    }
  }
}

// 默认即按当前完整链接（无附加参数时为渠道链接本身）预生成一次二维码，无需用户先点击「生成」。
void generateQrCode();

const copyWidgetCode = async () => {
  try {
    await navigator.clipboard.writeText(props.channel.widget_snippet);
    copiedWidgetCode.value = true;
    setTimeout(() => {
      copiedWidgetCode.value = false;
    }, 2000);
  } catch {
    copiedWidgetCode.value = false;
  }
};
</script>

<template>
  <Form
    :action="
      Web.UpdateWebChannelAccessAction.url({
        channel: props.channel.id,
      })
    "
    method="put"
    class="space-y-6"
  >
    <template #default="{ errors, processing }">
      <template
        v-for="(line, index) in allowedHostsLines"
        :key="`allowed-host-${index}`"
      >
        <input
          type="hidden"
          :name="`allowed_embed_hosts[${index}]`"
          :value="line"
        />
      </template>
      <input
        type="hidden"
        name="standalone_link_query"
        :value="normalizedLinkQuery"
      />

      <div class="max-w-2xl space-y-6">
        <div class="border-b border-border">
          <nav class="-mb-px flex flex-wrap gap-6">
            <button
              v-for="tab in subTabs"
              :key="tab.value"
              type="button"
              class="relative -mb-px border-b-2 px-1 pb-2 text-sm font-medium transition-colors"
              :class="
                accessSubTab === tab.value
                  ? 'border-primary text-foreground'
                  : 'border-transparent text-muted-foreground hover:text-foreground'
              "
              @click="accessSubTab = tab.value"
            >
              {{ tab.label }}
            </button>
          </nav>
        </div>

        <div v-show="accessSubTab === 'embed'" class="space-y-8">
          <section class="space-y-3">
            <div class="flex items-center gap-1.5">
              <Label>{{ t('网站嵌入代码') }}</Label>
              <Dialog>
                <DialogTrigger as-child>
                  <Button
                    variant="ghost"
                    type="button"
                    size="sm"
                    class="h-7 px-2"
                  >
                    <CircleHelp class="size-4" />
                    {{ t('接入指导') }}
                  </Button>
                </DialogTrigger>
                <DialogContent class="sm:max-w-2xl">
                  <DialogHeader>
                    <DialogTitle>{{ t('网站嵌入接入指导') }}</DialogTitle>
                    <DialogDescription>
                      {{
                        t(
                          '将安装代码添加到你的网站中；PC 端显示浮窗，移动端可自动铺满屏幕。',
                        )
                      }}
                    </DialogDescription>
                  </DialogHeader>
                  <div class="space-y-4 text-sm">
                    <div class="space-y-2">
                      <p class="font-medium">{{ t('基本用法') }}</p>
                      <pre
                        class="rounded-md border bg-muted/30 p-3 break-all whitespace-pre-wrap"
                        >{{ props.channel.widget_snippet }}</pre
                      >
                    </div>
                    <div class="space-y-2">
                      <p class="font-medium">{{ t('传入额外参数') }}</p>
                      <pre
                        class="rounded-md border bg-muted/30 p-3 break-all whitespace-pre-wrap"
                        >{{ widgetAdvancedSnippet }}</pre
                      >
                      <p class="text-muted-foreground">
                        {{
                          t(
                            'user_token 是你后端用「自定义传参」里的签名密钥签发的 HS256 JWT（sub 为用户 ID，必填，可选 name / email / exp），作为可信身份接入、防止伪造；visitor / params 为明文，仅适合来源、活动等非敏感信息。',
                          )
                        }}
                      </p>
                    </div>
                    <div class="space-y-2">
                      <p class="font-medium">{{ t('自定义入口按钮') }}</p>
                      <pre
                        class="rounded-md border bg-muted/30 p-3 break-all whitespace-pre-wrap"
                        >{{ declarativeTriggerSnippet }}</pre
                      >
                      <p class="text-muted-foreground">
                        {{
                          t(
                            '选择“自定义入口”后，默认气泡不会显示；你可以用 HelmDesk.show() 或 data-helmdesk-open 打开聊天。',
                          )
                        }}
                      </p>
                    </div>
                  </div>
                </DialogContent>
              </Dialog>
              <EmbedStatusCard :channel="props.channel" class="ml-auto" />
            </div>
            <div
              class="flex items-center gap-2 rounded-md border bg-muted/30 px-3 py-2 text-sm"
            >
              <code
                class="min-w-0 flex-1 font-mono break-all whitespace-pre-wrap"
              >
                {{ props.channel.widget_snippet }}
              </code>
              <Button
                variant="ghost"
                type="button"
                size="icon-sm"
                :aria-label="copiedWidgetCode ? t('已复制') : t('复制安装代码')"
                :title="copiedWidgetCode ? t('已复制') : t('复制安装代码')"
                @click="copyWidgetCode"
              >
                <Check v-if="copiedWidgetCode" class="size-4" />
                <Copy v-else class="size-4" />
              </Button>
            </div>
          </section>

          <section class="space-y-3">
            <div class="space-y-1">
              <Label for="access_allowed_embed_hosts">
                {{ t('嵌入域名白名单') }}
              </Label>
              <p class="text-sm text-muted-foreground">
                {{
                  t(
                    '每行一个允许加载网站嵌入代码的域名；留空或填写 * 表示不限制域名。',
                  )
                }}
              </p>
            </div>
            <Textarea
              id="access_allowed_embed_hosts"
              v-model="allowedHostsText"
              rows="4"
            />
            <InputError :message="errors.allowed_embed_hosts" />
          </section>
        </div>

        <div v-show="accessSubTab === 'standalone'" class="space-y-6">
          <section class="space-y-2">
            <div class="flex items-center gap-2">
              <Label for="standalone_link_query">{{ t('网站链接') }}</Label>
              <Dialog>
                <DialogTrigger as-child>
                  <Button
                    variant="ghost"
                    type="button"
                    size="sm"
                    class="h-7 px-2"
                  >
                    <CircleHelp class="size-4" />
                    {{ t('接入指导') }}
                  </Button>
                </DialogTrigger>
                <DialogContent class="sm:max-w-2xl">
                  <DialogHeader>
                    <DialogTitle>{{ t('聊天链接接入指导') }}</DialogTitle>
                    <DialogDescription>
                      {{
                        t(
                          '聊天链接可直接作为链接、按钮跳转地址或二维码落地页使用。',
                        )
                      }}
                    </DialogDescription>
                  </DialogHeader>
                  <div class="space-y-4 text-sm">
                    <div class="space-y-2">
                      <p class="font-medium">{{ t('基本用法') }}</p>
                      <pre
                        class="rounded-md border bg-muted/30 p-3 break-all whitespace-pre-wrap"
                        >{{ props.channel.standalone_url }}</pre
                      >
                    </div>
                    <div class="space-y-2">
                      <p class="font-medium">
                        {{ t('登录用户身份（签名）') }}
                      </p>
                      <pre
                        class="rounded-md border bg-muted/30 p-3 break-all whitespace-pre-wrap"
                        >{{ chatLinkSignedUrl }}</pre
                      >
                      <p class="text-muted-foreground">
                        {{
                          t(
                            '在「自定义传参」里设置签名密钥后，由你的后端用该密钥签发 HS256 JWT：sub 为你系统的用户 ID（必填），可选 name / email / exp。验签通过后访客以该身份接入，同一用户跨设备复用同一联系人。token 请勿写入日志或公开分享。',
                          )
                        }}
                      </p>
                    </div>
                    <div class="space-y-2">
                      <p class="font-medium">{{ t('带参数打开') }}</p>
                      <pre
                        class="rounded-md border bg-muted/30 p-3 break-all whitespace-pre-wrap"
                        >{{ chatLinkParamUrl }}</pre
                      >
                      <p class="text-muted-foreground">
                        {{
                          t(
                            'URL 参数会按“自定义传参”里的映射规则进入访客资料，适合来源、活动等非敏感信息；敏感身份请走上面的签名方式。',
                          )
                        }}
                      </p>
                    </div>
                  </div>
                </DialogContent>
              </Dialog>
            </div>
            <div class="grid gap-2 md:grid-cols-[2fr_1fr]">
              <Input
                :model-value="props.channel.standalone_url"
                readonly
                class="font-mono"
                :aria-label="t('渠道链接')"
              />
              <Input
                id="standalone_link_query"
                v-model="standaloneLinkQuery"
                class="font-mono"
                :placeholder="t('添加自定义参数')"
              />
            </div>
            <InputError :message="errors.standalone_link_query" />
          </section>

          <section class="space-y-2">
            <div class="flex items-center gap-2">
              <Label>{{ t('二维码') }}</Label>
              <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="qrCodeGenerating"
                @click="generateQrCode"
              >
                {{ t('更新') }}
              </Button>
            </div>
            <div
              class="flex size-40 shrink-0 items-center justify-center rounded-md border bg-white p-2"
            >
              <img
                v-if="chatLinkQrCodeDataUrl"
                :src="chatLinkQrCodeDataUrl"
                :alt="t('聊天链接二维码')"
                class="size-full"
              />
              <span v-else class="text-xs text-muted-foreground">
                {{ t('生成中...') }}
              </span>
            </div>
          </section>
        </div>

        <FormActions :submit-label="t('保存')" :processing="processing" />
      </div>
    </template>
  </Form>
</template>
