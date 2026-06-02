<?php

use Illuminate\Support\Facades\File;

test('单租户后台不再保留工作区常规设置页面', function (): void {
    $generalSetting = File::get(resource_path('js/pages/admin/generalSetting/Index.vue'));

    expect(File::exists(resource_path('js/pages/currentWorkspace/Index.vue')))->toBeFalse()
        ->and($generalSetting)->not->toContain("t('工作区ID不可修改')");
});

test('工作区模型表单移除输入类型配置和容量字段', function (): void {
    $contents = File::get(resource_path('js/pages/workspaceSettings/aiProviders/ModelFormDialog.vue'));

    expect($contents)
        ->not->toContain("t('支持的输入类型')")
        ->not->toContain("t('容量与限流')")
        ->not->toContain("t('配置模型容量')")
        ->not->toContain('placeholder')
        ->not->toContain('max_concurrency')
        ->not->toContain('requests_per_minute')
        ->not->toContain('tokens_per_minute');
});

test('工作区模型编辑表单显示不可修改字段并允许修改名称', function (): void {
    $contents = File::get(resource_path('js/pages/workspaceSettings/aiProviders/ModelFormDialog.vue'));

    expect($contents)
        ->toContain("return t('编辑模型')")
        ->toContain('<Select v-model="form.type" :disabled="isEdit">')
        ->toContain(':disabled="isEdit"')
        ->toContain('<Input v-model="form.name" autocomplete="off" />')
        ->not->toContain('v-if="!isBuiltinModel"')
        ->not->toContain('isBuiltinModel');
});

test('接待方案自动回复默认开启并使用真实初始文案', function (): void {
    $createPanel = File::get(resource_path('js/pages/reception/plans/CreatePlanDialog.vue'));
    $detailPage = File::get(resource_path('js/pages/reception/plans/Detail.vue'));

    $messages = [
        'ai_welcome' => '您好，我是{{display_name}}，请问有什么可以帮您？',
        'teammate_joined' => '您好，我是{{teammate_name}}，接下来由我为您服务。',
        'teammate_transferred' => '您好，我是{{teammate_name}}，已接手本次会话。',
    ];

    foreach ($messages as $trigger => $message) {
        $pattern = "/{$trigger}: \{\s*enabled: true,\s*message: t\('".preg_quote($message, '/')."'\),\s*\}/";

        expect(preg_match($pattern, $createPanel))->toBe(1);
    }

    expect($createPanel)->toContain('请保持友好、简洁、准确；先理解访客问题，再给出可执行答复。不确定时说明限制并询问关键信息。');

    $autoMessagesOffset = strpos($detailPage, "activePlanFormTab === 'auto_messages'");
    $capabilitiesOffset = strpos($detailPage, '<!-- 服务场景', $autoMessagesOffset);
    $autoMessagesBlock = substr($detailPage, $autoMessagesOffset, $capabilitiesOffset - $autoMessagesOffset);

    expect($autoMessagesBlock)->not->toContain(':placeholder=');
});

test('接待方案访客侧文案自动翻译默认关闭且等待选择供应商', function (): void {
    $createPanel = File::get(resource_path('js/pages/reception/plans/CreatePlanDialog.vue'));

    expect($createPanel)->toContain("translation_config: {\n      enabled: false,\n      failure_mode: 'skip',\n      provider_id: null,\n    }");
});

test('接待方案编辑页底部提供保存与返回操作且默认关闭 AI 引用访客消息', function (): void {
    $createPanel = File::get(resource_path('js/pages/reception/plans/CreatePlanDialog.vue'));
    $detailPage = File::get(resource_path('js/pages/reception/plans/Detail.vue'));
    $listPage = File::get(resource_path('js/pages/reception/plans/List.vue'));

    expect($createPanel)->toContain('quote_visitor_message_enabled: false');

    expect($detailPage)
        ->toContain('function savePlan(')
        ->toContain('@submit.prevent="savePlan"')
        ->toContain('<FormActions')
        ->toContain(":submit-label=\"t('保存')\"")
        ->toContain(':cancel-href="listUrl"')
        ->toContain(":cancel-label=\"t('返回')\"");

    expect($listPage)->toContain("t('管理系统接待方案配置，保存即生效。')");
});

test('接待方案自动回复开关使用 Switch 的 modelValue 绑定', function (): void {
    $contents = File::get(resource_path('js/pages/reception/plans/Detail.vue'));
    $autoMessagesOffset = strpos($contents, "activePlanFormTab === 'auto_messages'");
    $capabilitiesOffset = strpos($contents, '<!-- 服务场景', $autoMessagesOffset);
    $autoMessagesBlock = substr($contents, $autoMessagesOffset, $capabilitiesOffset - $autoMessagesOffset);

    expect($autoMessagesBlock)
        ->toContain(':model-value=')
        ->toContain('@update:model-value=')
        ->toContain('setAutoMessageEnabled(')
        ->not->toContain('v-model:checked');
});

test('接待方案自动回复标签紧跟营业时间标签', function (): void {
    $contents = File::get(resource_path('js/pages/reception/plans/Detail.vue'));
    $tabsOffset = strpos($contents, 'const planFormTabs');
    $businessHoursOffset = strpos($contents, "{ value: 'business_hours'", $tabsOffset);
    $autoMessagesOffset = strpos($contents, "{ value: 'auto_messages'", $tabsOffset);
    $receptionOffset = strpos($contents, "{ value: 'reception'", $tabsOffset);

    expect($tabsOffset)->not->toBeFalse()
        ->and($businessHoursOffset)->not->toBeFalse()
        ->and($autoMessagesOffset)->not->toBeFalse()
        ->and($receptionOffset)->not->toBeFalse()
        ->and($businessHoursOffset < $autoMessagesOffset)->toBeTrue()
        ->and($autoMessagesOffset < $receptionOffset)->toBeTrue();
});

test('接待方案回收站可查看已删除方案并恢复', function (): void {
    $trashPage = File::get(resource_path('js/pages/reception/plans/Trash.vue'));

    expect($trashPage)
        ->toContain('props.trashed_plan_list')
        ->toContain('RestoreConfirmDialog')
        ->toContain('RestoreReceptionPlanAction');
});

test('创建接待方案表单默认使用简洁语气且不提供空语气选项', function (): void {
    $contents = File::get(resource_path('js/pages/reception/plans/CreatePlanDialog.vue'));
    $createPage = File::get(resource_path('js/pages/reception/plans/Create.vue'));
    $removedToneOption = "t('不"."指定')";

    $displayNameOffset = strpos($contents, 'label-for="create_plan_persona_display_name"');
    $toneOffset = strpos($contents, 'label-for="create_plan_persona_tone"');
    $receptionModelOffset = strpos($contents, 'label-for="create_plan_reception_model"');

    expect($contents)
        ->toContain("persona_tone: 'concise'")
        ->not->toContain($removedToneOption)
        ->and($displayNameOffset)->not->toBeFalse()
        ->and($toneOffset)->not->toBeFalse()
        ->and($receptionModelOffset)->not->toBeFalse()
        ->and($displayNameOffset < $toneOffset)->toBeTrue()
        ->and($toneOffset < $receptionModelOffset)->toBeTrue();

    expect($createPage)->toContain(':persona-tone-options="props.persona_tone_options"');
});

test('接待方案编辑表单把对外昵称放在基础信息中', function (): void {
    $contents = File::get(resource_path('js/pages/reception/plans/PlanBasicsForm.vue'));
    $basicOffset = strpos($contents, "props.section === 'basic'");
    $displayNameOffset = strpos($contents, 'plan_basics_persona_display_name', $basicOffset);
    $translationOffset = strpos($contents, 'plan_basics_message_translation_enabled', $basicOffset);
    $receptionOffset = strpos($contents, "props.section === 'reception'", $basicOffset);
    $toneOffset = strpos($contents, 'plan_basics_persona_tone', $receptionOffset);

    expect($basicOffset)->not->toBeFalse()
        ->and($displayNameOffset)->not->toBeFalse()
        ->and($translationOffset)->not->toBeFalse()
        ->and($receptionOffset)->not->toBeFalse()
        ->and($toneOffset)->not->toBeFalse()
        ->and($displayNameOffset < $receptionOffset)->toBeTrue()
        ->and($displayNameOffset < $translationOffset)->toBeTrue()
        ->and($translationOffset < $receptionOffset)->toBeTrue()
        ->and($receptionOffset < $toneOffset)->toBeTrue();
});

test('收件箱翻译控件收进更多菜单且取消翻译请求不弹错误', function (): void {
    $inboxPage = File::get(resource_path('js/pages/Inbox.vue'));
    $toastComposable = File::get(resource_path('js/composables/useToast.ts'));

    expect($inboxPage)
        ->toContain('toggleAutoTranslateVisibleMessages')
        ->toContain('toggleReplyAutoTranslate')
        ->toContain("import { useInboxAutoTranslate } from '@/composables/useInboxAutoTranslate';")
        ->toContain("t('打开自动翻译')")
        ->toContain('<Languages class="size-3.5" />')
        ->toContain('<Languages class="size-4" />')
        ->toContain('@select="toggleAutoTranslateVisibleMessages()"')
        ->toContain('@select="closeConversation()"')
        ->toContain('@select="toggleTimelineEvents()"')
        ->toContain('@select="reopenConversation()"')
        ->toContain('<RotateCcw class="size-3.5" />');

    expect(strpos($inboxPage, '@select="closeConversation()"'))->toBeGreaterThan(
        strpos($inboxPage, '@select="toggleTimelineEvents()"'),
    );

    expect($toastComposable)->toContain("error?.code === 'ERR_CANCELED'");
});

test('收件箱会话顶部星标操作与联系人昵称同组且资料面板固定显示重点客户值', function (): void {
    $inboxPage = File::get(resource_path('js/pages/Inbox.vue'));
    $contextPanel = File::get(resource_path('js/pages/inbox/InboxContextPanel.vue'));

    // 提取 header 内容，避免匹配到页面其他区域的同名字符串
    preg_match(
        '!<header class="flex shrink-0 items-center gap-3 border-b px-4 py-3">(.*?)</header>!s',
        $inboxPage,
        $headerMatch,
    );
    $headerContent = $headerMatch[1] ?? '';

    $importancePos = strpos($headerContent, ':aria-label="importanceToggleTitle"');
    $visitorNamePos = strpos($headerContent, 'formatVisitorName(');

    expect($importancePos)->toBeInt('页头区域内未找到星标操作按钮')
        ->and($visitorNamePos)->toBeInt('页头区域内未找到访客名称展示')
        ->and($importancePos)->toBeLessThan($visitorNamePos, '星标按钮应在访客名称之前');

    expect($contextPanel)
        ->toContain("key: 'important'")
        ->toContain("profile.is_important ? t('是') : t('否')");
});

test('接待方案流程策略使用接待方式文案并补齐多语言', function (): void {
    $strategyForm = File::get(resource_path('js/pages/reception/plans/PlanStrategyForm.vue'));
    $zhLocale = File::get(resource_path('js/locales/zh-CN/workspace-settings.ts'));
    $enLocale = File::get(resource_path('js/locales/en/workspace-settings.ts'));

    expect($strategyForm)->toContain("t('接待方式')");

    foreach (['流程策略', '接待方式', 'AI 优先', '人工优先'] as $copyKey) {
        expect($zhLocale)->toContain($copyKey)
            ->and($enLocale)->toContain($copyKey);
    }
});

test('大模型供应商页面不再展示模型输入类型标签', function (): void {
    $contents = File::get(resource_path('js/pages/workspaceSettings/aiProviders/Index.vue'));

    expect($contents)
        ->not->toContain('capabilityLabel')
        ->not->toContain('model.capabilities');
});

test('原工作区设置菜单已迁入总管理后台和系统设置二级视图', function (): void {
    $systemSidebarLayout = File::get(resource_path('js/layouts/app/SystemSidebarLayout.vue'));
    $systemSettingsLayout = File::get(resource_path('js/layouts/SystemSettingsLayout.vue'));

    $mainMenuMappings = [
        'Plan.ShowReceptionPlanIndexPageAction.url()' => '`${manageBaseUrl.value}/reception`',
        'workspace.manage.channels.web.index.url()' => '`${manageBaseUrl.value}/channels`',
    ];

    $systemSettingMenuMappings = [
        'admin.general.show.url()',
        'admin.storage.show.url()',
        'admin.mail.show.url()',
        'workspace.manage.tags.index.url()',
        'workspace.manage.attributes.index.url()',
        'workspace.cannedReplies.index.url()',
        'workspace.manage.ai.providers.index.url()',
        'workspace.manage.mcp.servers.index.url()',
        'workspace.manage.translation.providers.index.url()',
    ];

    expect(File::exists(resource_path('js/layouts/WorkspaceSettingsLayout.vue')))->toBeFalse()
        ->and(File::exists(resource_path('js/layouts/app/AppSidebarLayout.vue')))->toBeFalse()
        ->and($systemSidebarLayout)->toContain("title: t('系统设置')")
        ->and($systemSidebarLayout)->toContain('href: admin.general.show.url()')
        ->and($systemSidebarLayout)->not->toContain("title: t('标签')")
        ->and($systemSidebarLayout)->not->toContain("title: t('自定义属性')")
        ->and($systemSidebarLayout)->not->toContain("title: t('快捷回复')")
        ->and($systemSidebarLayout)->not->toContain("title: t('大模型供应商')")
        ->and($systemSidebarLayout)->not->toContain("title: t('MCP 服务')")
        ->and($systemSidebarLayout)->not->toContain("title: t('翻译供应商')");

    foreach ($mainMenuMappings as $menuSignature => $activeSignature) {
        expect($systemSidebarLayout)->toContain($menuSignature)
            ->and($systemSidebarLayout)->toContain($activeSignature);
    }

    foreach ($systemSettingMenuMappings as $menuSignature) {
        expect($systemSettingsLayout)->toContain($menuSignature);
    }
});

test('渠道菜单在总管理后台使用一级入口和二级切换', function (): void {
    $systemSidebarLayout = File::get(resource_path('js/layouts/app/SystemSidebarLayout.vue'));
    $channelsLayout = File::get(resource_path('js/layouts/ChannelsLayout.vue'));

    expect($systemSidebarLayout)
        ->toContain("title: t('渠道管理')")
        ->not->toContain("title: t('渠道')")
        ->not->toContain("title: t('网站渠道')")
        ->not->toContain("title: t('Telegram 渠道')");

    expect($channelsLayout)
        ->toContain('sidebarNavItems')
        ->toContain("title: t('网站')")
        ->toContain("title: t('Telegram')")
        ->toContain('workspace.manage.channels.web.index.url()')
        ->toContain('workspace.manage.channels.telegram.index.url()');
});

test('系统设置页面使用页面级二级菜单视图', function (): void {
    $pages = [
        'js/pages/admin/generalSetting/Index.vue',
        'js/pages/admin/storageSetting/Index.vue',
        'js/pages/admin/storageSetting/Create.vue',
        'js/pages/admin/storageSetting/Edit.vue',
        'js/pages/admin/systemSettings/MailSetting.vue',
        'js/pages/tags/Index.vue',
        'js/pages/tags/Trash.vue',
        'js/pages/workspaceSettings/datas/Attribute.vue',
        'js/pages/workspaceSettings/datas/AttributeTrash.vue',
        'js/pages/cannedReplies/Index.vue',
        'js/pages/workspaceSettings/aiProviders/Index.vue',
        'js/pages/workspaceSettings/mcpServers/Index.vue',
        'js/pages/workspaceSettings/translationProviders/Index.vue',
    ];

    foreach ($pages as $page) {
        expect(File::get(resource_path($page)))
            ->toContain("import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue'")
            ->toContain('<SystemSettingsLayout>');
    }
});
