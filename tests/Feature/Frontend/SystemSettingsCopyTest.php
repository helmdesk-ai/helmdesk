<?php

use Illuminate\Support\Facades\File;

test('系统模型编辑表单显示不可修改字段并允许修改名称', function (): void {
    // AI 模型从供应商页拆出，独立到「AI 模型管理」页；编辑态下供应商 / 用途 / 模型 ID 只读，仅名称与启用可改。
    $editPage = File::get(resource_path('js/pages/systemSettings/aiModels/Edit.vue'));
    $modelForm = File::get(resource_path('js/pages/systemSettings/aiModels/ModelForm.vue'));

    expect($editPage)->toContain("t('编辑模型')");

    expect($modelForm)
        ->toContain('isEditMode')
        ->toContain('v-if="!isEditMode"')
        ->toContain('disabled')
        ->toContain('v-model="form.name"');
});

test('接待方案自动回复默认开启并使用真实初始文案', function (): void {
    $createPanel = File::get(resource_path('js/pages/reception/plans/CreatePlanDialog.vue'));

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
        ->toContain('setAutoMessageEnabled(');
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

test('创建接待方案表单默认使用简洁语气并展示核心字段', function (): void {
    $contents = File::get(resource_path('js/pages/reception/plans/CreatePlanDialog.vue'));
    $createPage = File::get(resource_path('js/pages/reception/plans/Create.vue'));

    // 接待方案不再有按方案选模型的下拉（模型改由全局用途池路由）；只校验昵称与语气字段顺序。
    $displayNameOffset = strpos($contents, 'label-for="create_plan_persona_display_name"');
    $toneOffset = strpos($contents, 'label-for="create_plan_persona_tone"');

    expect($contents)
        ->toContain("persona_tone: 'concise'")
        ->and($displayNameOffset)->not->toBeFalse()
        ->and($toneOffset)->not->toBeFalse()
        ->and($displayNameOffset < $toneOffset)->toBeTrue();

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
    $zhLocale = File::get(resource_path('js/locales/zh-CN/system-admin.ts'));
    $enLocale = File::get(resource_path('js/locales/en/system-admin.ts'));

    expect($strategyForm)->toContain("t('接待方式')");

    foreach (['流程策略', '接待方式', 'AI 优先', '人工优先'] as $copyKey) {
        expect($zhLocale)->toContain($copyKey)
            ->and($enLocale)->toContain($copyKey);
    }
});

test('侧边栏品牌区仅展示系统名称', function (): void {
    $sidebarShell = File::get(resource_path('js/layouts/app/SidebarShell.vue'));
    $systemSidebarLayout = File::get(resource_path('js/layouts/app/SystemSidebarLayout.vue'));

    expect($sidebarShell)
        ->toContain('{{ systemName }}')
        ->toContain('class="flex min-w-0 flex-1 items-center pr-2 group-data-[collapsible=icon]:hidden"')
        ->toContain('class="truncate text-sm leading-tight font-semibold"')
        ->not->toContain('headerSubtitle')
        ->not->toContain('name="headerSubtitle"');

    expect($systemSidebarLayout)
        ->toContain(':header-href="admin.dashboard.url()"')
        ->not->toContain('header-subtitle')
        ->not->toContain('总管理后台');
});

test('系统设置菜单使用系统设置二级视图', function (): void {
    $systemSidebarLayout = File::get(resource_path('js/layouts/app/SystemSidebarLayout.vue'));
    $systemSettingsLayout = File::get(resource_path('js/layouts/SystemSettingsLayout.vue'));

    $mainMenuMappings = [
        'admin.manage.tags.index.url()' => '`${manageBaseUrl.value}/tags`',
        'admin.manage.attributes.index.url()' => '`${manageBaseUrl.value}/attributes`',
        'admin.cannedReplies.index.url()' => 'routePath(admin.cannedReplies.index.url())',
        'KnowledgeBase.ListKnowledgeBasesAction.url()' => '`${manageBaseUrl.value}/knowledge-bases`',
        'Plan.ShowReceptionPlanIndexPageAction.url()' => '`${manageBaseUrl.value}/reception`',
        'admin.manage.channels.web.index.url()' => '`${manageBaseUrl.value}/channels`',
    ];

    $systemSettingMenuMappings = [
        'admin.general.show.url()',
        'admin.storage.show.url()',
        'admin.mail.show.url()',
        'admin.manage.ai.providers.index.url()',
        'admin.manage.mcp.servers.index.url()',
        'admin.manage.translation.providers.index.url()',
    ];

    expect(File::exists(resource_path('js/layouts/SystemSettingsLayout.vue')))->toBeTrue()
        ->and($systemSidebarLayout)->toContain("title: t('系统设置')")
        ->and($systemSidebarLayout)->toContain('href: admin.general.show.url()')
        ->and($systemSidebarLayout)->toContain('canManageSystemSettings');

    foreach ($mainMenuMappings as $menuSignature => $activeSignature) {
        expect($systemSidebarLayout)->toContain($menuSignature)
            ->and($systemSidebarLayout)->toContain($activeSignature);
    }

    foreach ($systemSettingMenuMappings as $menuSignature) {
        expect($systemSettingsLayout)->toContain($menuSignature);
    }
});

test('渠道菜单在后台使用一级入口和二级切换', function (): void {
    $systemSidebarLayout = File::get(resource_path('js/layouts/app/SystemSidebarLayout.vue'));
    $channelsLayout = File::get(resource_path('js/layouts/ChannelsLayout.vue'));

    expect($systemSidebarLayout)
        ->toContain("title: t('渠道管理')");

    expect($channelsLayout)
        ->toContain('sidebarNavItems')
        ->toContain("title: t('网站')")
        ->toContain("title: t('Telegram')")
        ->toContain('admin.manage.channels.web.index.url()')
        ->toContain('admin.manage.channels.telegram.index.url()');
});

test('系统设置页面使用页面级二级菜单视图', function (): void {
    $pages = [
        'js/pages/admin/generalSetting/Index.vue',
        'js/pages/admin/storageSetting/Index.vue',
        'js/pages/admin/storageSetting/Create.vue',
        'js/pages/admin/storageSetting/Edit.vue',
        'js/pages/admin/systemSettings/MailSetting.vue',
        'js/pages/systemSettings/aiProviders/Index.vue',
        'js/pages/systemSettings/mcpServers/Index.vue',
        'js/pages/systemSettings/mcpServers/Create.vue',
        'js/pages/systemSettings/mcpServers/Edit.vue',
        'js/pages/systemSettings/translationProviders/Index.vue',
        'js/pages/systemSettings/translationProviders/Create.vue',
        'js/pages/systemSettings/translationProviders/Edit.vue',
    ];

    foreach ($pages as $page) {
        expect(File::get(resource_path($page)))
            ->toContain("import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue'")
            ->toContain('<SystemSettingsLayout');
    }
});

test('一级业务菜单页面不使用系统设置二级布局', function (): void {
    $pages = [
        'js/pages/tags/Index.vue',
        'js/pages/tags/Trash.vue',
        'js/pages/systemSettings/datas/Attribute.vue',
        'js/pages/systemSettings/datas/AttributeTrash.vue',
        'js/pages/cannedReplies/Index.vue',
    ];

    foreach ($pages as $page) {
        expect(File::get(resource_path($page)))
            ->not->toContain("import SystemSettingsLayout from '@/layouts/SystemSettingsLayout.vue'")
            ->not->toContain('<SystemSettingsLayout');
    }
});
