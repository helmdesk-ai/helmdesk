<?php

use Illuminate\Support\Facades\File;

test('工作区常规设置不再展示工作区 ID 不可修改提示', function (): void {
    $contents = File::get(resource_path('js/pages/currentWorkspace/Index.vue'));

    expect($contents)->not->toContain("t('工作区ID不可修改')");
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

    expect($listPage)->toContain("t('管理工作区的接待方案配置，保存即生效。')");
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

test('工作区设置二级菜单都归属一级设置菜单高亮范围', function (): void {
    $workspaceSettingsLayout = File::get(resource_path('js/layouts/WorkspaceSettingsLayout.vue'));
    $appSidebarLayout = File::get(resource_path('js/layouts/app/AppSidebarLayout.vue'));

    preg_match(
        '/const sidebarNavItems = computed<SubMenuItem\[\]>\(\(\) => \{(?P<body>.*?)\n\}\);/s',
        $workspaceSettingsLayout,
        $matches,
    );
    $menuBody = $matches['body'] ?? '';

    preg_match_all('/\bhref:/', $menuBody, $hrefMatches);

    $menuMappings = [
        'workspaceRoutes.manage.workspaces.current.show.url(' => '`${manageBaseUrl.value}/workspaces`',
        'workspaceRoutes.manage.tags.index.url(currentWorkspace.value.slug)' => '`${manageBaseUrl.value}/tags`',
        'workspaceRoutes.manage.attributes.index.url(' => '`${manageBaseUrl.value}/attributes`',
        'workspaceRoutes.cannedReplies.index.url(currentWorkspace.value.slug)' => 'workspace.cannedReplies.index.url(currentWorkspace.value.slug)',
        'AiProvider.ShowWorkspaceAiProvidersAction.url(' => '`${manageBaseUrl.value}/ai`',
        'Translation.ShowWorkspaceTranslationProvidersAction.url(' => '`${manageBaseUrl.value}/translation`',
        'Mcp.ShowWorkspaceMcpServersAction.url(' => '`${manageBaseUrl.value}/mcp-servers`',
    ];

    expect($menuBody)->not->toBe('')
        ->and($hrefMatches[0])->toHaveCount(count($menuMappings));

    foreach ($menuMappings as $menuSignature => $activeSignature) {
        expect($workspaceSettingsLayout)->toContain($menuSignature)
            ->and($appSidebarLayout)->toContain($activeSignature);
    }

    expect($workspaceSettingsLayout)->not->toContain('Plan.ShowReceptionPlanIndexPageAction.url(')
        ->and($appSidebarLayout)->toContain('Plan.ShowReceptionPlanIndexPageAction.url(')
        ->and($appSidebarLayout)->toContain('`${manageBaseUrl.value}/reception`');
});
