<?php

use App\Actions\AiChat\SendAiAssistantMessageAction;
use App\Actions\AiChat\StopAiAssistantMessageAction;
use App\Actions\AiProvider\CheckAiProviderAction;
use App\Actions\AiProvider\CreateAiModelAction;
use App\Actions\AiProvider\CreateAiProviderAction;
use App\Actions\AiProvider\DeleteAiModelAction;
use App\Actions\AiProvider\DeleteAiProviderAction;
use App\Actions\AiProvider\ShowSystemAiProvidersAction;
use App\Actions\AiProvider\ToggleAiModelAction;
use App\Actions\AiProvider\UpdateAiProviderCredentialsAction;
use App\Actions\CannedReply\CreateCannedReplyAction;
use App\Actions\CannedReply\DeleteCannedReplyAction;
use App\Actions\CannedReply\SearchCannedRepliesForComposerAction;
use App\Actions\CannedReply\ShowCannedReplyListAction;
use App\Actions\CannedReply\UpdateCannedReplyAction;
use App\Actions\CannedReply\UseAndRenderCannedReplyAction;
use App\Actions\Channel\Telegram\CreateTelegramChannelAction;
use App\Actions\Channel\Telegram\DeleteTelegramChannelAction;
use App\Actions\Channel\Telegram\ListTelegramChannelsAction;
use App\Actions\Channel\Telegram\ListTelegramChannelTrashAction;
use App\Actions\Channel\Telegram\RegisterTelegramWebhookAction;
use App\Actions\Channel\Telegram\RestoreTelegramChannelAction;
use App\Actions\Channel\Telegram\ShowCreateTelegramChannelPageAction;
use App\Actions\Channel\Telegram\ShowTelegramChannelDetailPageAction;
use App\Actions\Channel\Telegram\UpdateTelegramChannelBasicAction;
use App\Actions\Channel\Telegram\UpdateTelegramChannelTokenAction;
use App\Actions\Channel\Web\CreateWebChannelAction;
use App\Actions\Channel\Web\DeleteWebChannelAction;
use App\Actions\Channel\Web\ListWebChannelsAction;
use App\Actions\Channel\Web\ListWebChannelTrashAction;
use App\Actions\Channel\Web\RegenerateWebChannelUserTokenSecretAction;
use App\Actions\Channel\Web\RestoreWebChannelAction;
use App\Actions\Channel\Web\ShowCreateWebChannelPageAction;
use App\Actions\Channel\Web\ShowWebChannelDetailPageAction;
use App\Actions\Channel\Web\ShowWebChannelPreviewFrameAction;
use App\Actions\Channel\Web\UpdateWebChannelAccessAction;
use App\Actions\Channel\Web\UpdateWebChannelBasicAction;
use App\Actions\Channel\Web\UpdateWebChannelEmbedAction;
use App\Actions\Channel\Web\UpdateWebChannelVisitorInterfaceAction;
use App\Actions\Channel\Web\UpdateWebChannelWidgetAction;
use App\Actions\Contact\CreateContactAction;
use App\Actions\Contact\CreateContactIdentityAction;
use App\Actions\Contact\DeleteContactAction;
use App\Actions\Contact\DeleteContactIdentityAction;
use App\Actions\Contact\GetContactTrashListAction;
use App\Actions\Contact\MergeContactsAction;
use App\Actions\Contact\ReplaceContactIdentityAction;
use App\Actions\Contact\RestoreContactAction;
use App\Actions\Contact\ShowContactDetailAction;
use App\Actions\Contact\ShowContactListAction;
use App\Actions\Contact\UpdateContactAction;
use App\Actions\Contact\UpdateContactImportanceAction;
use App\Actions\Conversation\AttachConversationTagAction;
use App\Actions\Conversation\DetachConversationTagAction;
use App\Actions\Conversation\ShowConversationDetailAction;
use App\Actions\Conversation\ShowConversationListAction;
use App\Actions\CustomAttribute\ArchiveAttributeDefinitionAction;
use App\Actions\CustomAttribute\CreateAttributeDefinitionAction;
use App\Actions\CustomAttribute\ReorderAttributeDefinitionsAction;
use App\Actions\CustomAttribute\RestoreAttributeDefinitionAction;
use App\Actions\CustomAttribute\ShowAttributeDefinitionListAction;
use App\Actions\CustomAttribute\ShowAttributeDefinitionTrashAction;
use App\Actions\CustomAttribute\UpdateAttributeDefinitionAction;
use App\Actions\CustomAttribute\UpdateContactAttributeValuesAction;
use App\Actions\Dashboard\ShowDashboardPageAction;
use App\Actions\Home\ShowHomePageAction;
use App\Actions\Inbox\ClaimInboxConversationAction;
use App\Actions\Inbox\CloseInboxConversationAction;
use App\Actions\Inbox\LoadInboxContactTimelineAction;
use App\Actions\Inbox\MarkInboxConversationReadAction;
use App\Actions\Inbox\PolishInboxReplyAction;
use App\Actions\Inbox\PreviewInboxReplyTranslationAction;
use App\Actions\Inbox\QueueInboxContactAiSummaryTranslationAction;
use App\Actions\Inbox\QueueInboxConversationMessageTranslationsAction;
use App\Actions\Inbox\QueueInboxConversationSummaryTranslationsAction;
use App\Actions\Inbox\RecallInboxConversationMessageAction;
use App\Actions\Inbox\ReleaseInboxConversationToAiAction;
use App\Actions\Inbox\ReopenInboxConversationAction;
use App\Actions\Inbox\ReplyInboxConversationAction;
use App\Actions\Inbox\SearchInboxMessagesAction;
use App\Actions\Inbox\ShowInboxAction;
use App\Actions\Inbox\TransferInboxConversationAction;
use App\Actions\Inbox\UpdateConversationVisitorLocaleAction;
use App\Actions\KnowledgeBase\CreateKnowledgeBaseAction;
use App\Actions\KnowledgeBase\DeleteKnowledgeBaseAction;
use App\Actions\KnowledgeBase\Document\CreateManualKnowledgeDocumentAction;
use App\Actions\KnowledgeBase\Document\DeleteKnowledgeDocumentAction;
use App\Actions\KnowledgeBase\Document\MoveKnowledgeDocumentAction;
use App\Actions\KnowledgeBase\Document\StreamKnowledgeDocumentPreviewFileAction;
use App\Actions\KnowledgeBase\Document\UpdateManualKnowledgeDocumentAction;
use App\Actions\KnowledgeBase\Document\UploadKnowledgeDocumentAction;
use App\Actions\KnowledgeBase\Group\CreateKnowledgeGroupAction;
use App\Actions\KnowledgeBase\Group\DeleteKnowledgeGroupAction;
use App\Actions\KnowledgeBase\Group\UpdateKnowledgeGroupAction;
use App\Actions\KnowledgeBase\Indexing\ReindexKnowledgeDocumentAction;
use App\Actions\KnowledgeBase\ListKnowledgeBasesAction;
use App\Actions\KnowledgeBase\Qa\CreateKnowledgeQaEntryAction;
use App\Actions\KnowledgeBase\Qa\DeleteKnowledgeQaEntryAction;
use App\Actions\KnowledgeBase\Qa\MoveKnowledgeQaEntryAction;
use App\Actions\KnowledgeBase\Qa\UpdateKnowledgeQaEntryAction;
use App\Actions\KnowledgeBase\RunKnowledgeRecallTestAction;
use App\Actions\KnowledgeBase\ShowCreateKnowledgeBasePageAction;
use App\Actions\KnowledgeBase\ShowEditKnowledgeBasePageAction;
use App\Actions\KnowledgeBase\UpdateKnowledgeBaseAction;
use App\Actions\KnowledgeBase\UpdateSystemKnowledgeSettingsAction;
use App\Actions\Mcp\CheckMcpServerAction;
use App\Actions\Mcp\CreateMcpServerAction;
use App\Actions\Mcp\DeleteMcpServerAction;
use App\Actions\Mcp\ShowSystemMcpServersAction;
use App\Actions\Mcp\SyncMcpServerToolsAction;
use App\Actions\Mcp\ToggleMcpServerAction;
use App\Actions\Mcp\ToggleMcpToolAction;
use App\Actions\Mcp\UpdateMcpServerAction;
use App\Actions\Reception\Plan\CreateReceptionPlanAction;
use App\Actions\Reception\Plan\DeleteReceptionPlanAction;
use App\Actions\Reception\Plan\ListReceptionPlanTrashAction;
use App\Actions\Reception\Plan\RestoreReceptionPlanAction;
use App\Actions\Reception\Plan\ShowCreateReceptionPlanPageAction;
use App\Actions\Reception\Plan\ShowReceptionPlanDetailPageAction;
use App\Actions\Reception\Plan\ShowReceptionPlanIndexPageAction;
use App\Actions\Reception\Plan\UpdateReceptionPlanAction;
use App\Actions\Security\LogoutAdminAction;
use App\Actions\Security\LogoutWebAction;
use App\Actions\StorageSetting\CheckStorageSettingAction;
use App\Actions\StorageSetting\GetStorageSettingAction;
use App\Actions\StorageSetting\StorageProfile\CheckStorageProfileAction;
use App\Actions\StorageSetting\StorageProfile\CreateStorageProfileAction;
use App\Actions\StorageSetting\StorageProfile\DeleteStorageProfileAction;
use App\Actions\StorageSetting\StorageProfile\ShowCreateStorageProfilePageAction;
use App\Actions\StorageSetting\StorageProfile\ShowEditStorageProfilePageAction;
use App\Actions\StorageSetting\StorageProfile\UpdateStorageProfileAction;
use App\Actions\StorageSetting\UpdateStorageSettingAction;
use App\Actions\SystemSetting\GetGeneralSettingAction;
use App\Actions\SystemSetting\SendMailSettingsTestEmailAction;
use App\Actions\SystemSetting\ShowMailSettingsPageAction;
use App\Actions\SystemSetting\UpdateGeneralSettingAction;
use App\Actions\SystemSetting\UpdateMailSettingsAction;
use App\Actions\Tag\AttachContactTagAction;
use App\Actions\Tag\CreateTagAction;
use App\Actions\Tag\CreateTagGroupAction;
use App\Actions\Tag\DeleteTagAction;
use App\Actions\Tag\DeleteTagGroupAction;
use App\Actions\Tag\DetachContactTagAction;
use App\Actions\Tag\ListTagUsageAction;
use App\Actions\Tag\MergeTagsAction;
use App\Actions\Tag\RestoreTagAction;
use App\Actions\Tag\ShowTagListAction;
use App\Actions\Tag\ShowTagTrashAction;
use App\Actions\Tag\UpdateTagAction;
use App\Actions\Tag\UpdateTagGroupAction;
use App\Actions\Translation\CheckTranslationProviderAction;
use App\Actions\Translation\ClearTranslationProviderCredentialsAction;
use App\Actions\Translation\CreateTranslationProviderAction;
use App\Actions\Translation\DeleteTranslationProviderAction;
use App\Actions\Translation\ShowSystemTranslationProvidersAction;
use App\Actions\Translation\UpdateTranslationProviderCredentialsAction;
use App\Actions\User\DeleteProfileAction;
use App\Actions\User\ShowAppearanceSettingsPageAction;
use App\Actions\User\ShowLanguageSettingsPageAction;
use App\Actions\User\ShowNotificationSettingsPageAction;
use App\Actions\User\ShowPasswordSettingsPageAction;
use App\Actions\User\ShowProfileSettingsPageAction;
use App\Actions\User\ShowTwoFactorAuthenticationSettingsPageAction;
use App\Actions\User\UpdateLanguageSettingsAction;
use App\Actions\User\UpdateMyOnlineStatusAction;
use App\Actions\User\UpdateNotificationSettingsAction;
use App\Actions\User\UpdatePasswordAction;
use App\Actions\User\UpdateProfileAction;
use App\Http\Middleware\CheckSuperAdmin;
use App\Http\Middleware\ConfirmPasswordWhenTwoFactorRequiresIt;
use App\Http\Middleware\EnsureEmailIsVerifiedWhenMailEnabled;
use App\Http\Middleware\IdentifySystem;
use Illuminate\Support\Facades\Route;

Route::get('/', ShowHomePageAction::class)->name('home');
Route::redirect('/dashboard', '/admin/dashboard')->middleware(['auth:admin,web'])->name('dashboard');
Route::redirect('/inbox', '/admin/inbox')->middleware(['auth:admin,web'])->name('inbox');

// 网站渠道详情页右侧实时预览所嵌入的 iframe 文档（哑壳，渠道草稿由父页面 postMessage 注入）
Route::get('/channels/web/preview', ShowWebChannelPreviewFrameAction::class)->middleware(['auth:admin,web'])->name('channels.web.preview');

// 个人设置
Route::middleware(['auth:admin,web', CheckSuperAdmin::class, IdentifySystem::class])->prefix('settings')->group(function () {
    Route::redirect('/', '/settings/profile');

    // 个人资料
    Route::get('profile', ShowProfileSettingsPageAction::class)->name('settings.profile.edit');
    Route::patch('profile', UpdateProfileAction::class)->name('settings.profile.update');
    Route::delete('profile', DeleteProfileAction::class)->name('settings.profile.destroy');

    // 密码
    Route::get('password', ShowPasswordSettingsPageAction::class)->name('settings.password.edit');
    Route::put('password', UpdatePasswordAction::class)->middleware('throttle:6,1')->name('settings.password.update');

    // 两步认证
    Route::get('two-factor', ShowTwoFactorAuthenticationSettingsPageAction::class)
        ->middleware(ConfirmPasswordWhenTwoFactorRequiresIt::class)
        ->name('settings.two-factor.show');

    // 语言和时区
    Route::get('language', ShowLanguageSettingsPageAction::class)->name('settings.language.edit');
    Route::put('language', UpdateLanguageSettingsAction::class)->name('settings.language.update');

    // 外观
    Route::get('appearance', ShowAppearanceSettingsPageAction::class)->name('settings.appearance.edit');

    // 通知
    Route::get('notifications', ShowNotificationSettingsPageAction::class)->name('settings.notifications.edit');
    Route::put('notifications', UpdateNotificationSettingsAction::class)->name('settings.notifications.update');
});

// 系统设置（仅超级管理员）
Route::prefix('admin')->middleware(['auth:admin,web', CheckSuperAdmin::class, IdentifySystem::class])->group(function () {
    Route::redirect('/', '/admin/dashboard')->name('admin.home');

    // 基础设置
    Route::get('general', GetGeneralSettingAction::class)->name('admin.general.show');
    Route::put('general', UpdateGeneralSettingAction::class)->name('admin.general.update');

    // 存储设置
    Route::get('storage', GetStorageSettingAction::class)->name('admin.storage.show');
    Route::put('storage', UpdateStorageSettingAction::class)->name('admin.storage.update');
    Route::put('storage/check', CheckStorageSettingAction::class)->name('admin.storage.check');
    Route::get('storage/profiles/create', ShowCreateStorageProfilePageAction::class)->name('admin.storage.profiles.create');
    Route::post('storage/profiles', CreateStorageProfileAction::class)->name('admin.storage.profiles.store');
    Route::get('storage/profiles/{profile}/edit', ShowEditStorageProfilePageAction::class)->name('admin.storage.profiles.edit');
    Route::put('storage/profiles/{profile}', UpdateStorageProfileAction::class)->name('admin.storage.profiles.update');
    Route::put('storage/profiles/{profile}/check', CheckStorageProfileAction::class)->name('admin.storage.profiles.check');
    Route::delete('storage/profiles/{profile}', DeleteStorageProfileAction::class)->name('admin.storage.profiles.destroy');

    // 邮箱服务器
    Route::get('mail', ShowMailSettingsPageAction::class)->name('admin.mail.show');
    Route::put('mail', UpdateMailSettingsAction::class)->name('admin.mail.update');
    Route::post('mail/test', SendMailSettingsTestEmailAction::class)->name('admin.mail.test');
});

// 分 guard 登出
Route::post('/logout/admin', LogoutAdminAction::class)->middleware(['auth:admin'])->name('logout.admin');
Route::post('/logout/web', LogoutWebAction::class)->middleware(['auth:web'])->name('logout.web');

Route::prefix('admin')->middleware(['auth:admin,web', CheckSuperAdmin::class, EnsureEmailIsVerifiedWhenMailEnabled::class, IdentifySystem::class])->group(function () {
    Route::redirect('/home', '/admin/dashboard')->name('admin.home.redirect');
    Route::get('/dashboard', ShowDashboardPageAction::class)->name('admin.dashboard');
    Route::get('/inbox', ShowInboxAction::class)->name('admin.inbox.show');
    Route::put('/online-status', UpdateMyOnlineStatusAction::class)->name('admin.online-status.update');

    // 收件箱上会话级动作（已读 / 回复 / 接单 / 转接 / 关单）。GET /inbox 就是 Inbox 本体，
    // 这些 POST 端点都挂在 /inbox/{conversation} 下，保持与页面 URL 一致。
    Route::prefix('inbox')->group(function () {
        Route::get('contacts/{contactId}/timeline', LoadInboxContactTimelineAction::class)->name('admin.inbox.contacts.timeline');
        Route::get('contacts/{contactId}/messages/search', SearchInboxMessagesAction::class)->name('admin.inbox.contacts.messages.search');
        Route::post('contacts/{contactId}/ai-summary/queue-translation', QueueInboxContactAiSummaryTranslationAction::class)->name('admin.inbox.contacts.ai-summary.queue-translation');
        Route::post('{conversation}/read', MarkInboxConversationReadAction::class)->name('admin.inbox.conversations.read');
        Route::post('{conversation}/reply', ReplyInboxConversationAction::class)->name('admin.inbox.conversations.reply');
        Route::post('{conversation}/reply/polish', PolishInboxReplyAction::class)->name('admin.inbox.conversations.reply.polish');
        Route::post('{conversation}/reply/translation-preview', PreviewInboxReplyTranslationAction::class)->name('admin.inbox.conversations.reply.translation-preview');
        Route::post('{conversation}/messages/queue-translations', QueueInboxConversationMessageTranslationsAction::class)->name('admin.inbox.conversations.messages.queue-translations');
        Route::post('{conversation}/summaries/queue-translations', QueueInboxConversationSummaryTranslationsAction::class)->name('admin.inbox.conversations.summaries.queue-translations');
        Route::post('{conversation}/messages/{message}/recall', RecallInboxConversationMessageAction::class)->name('admin.inbox.conversations.messages.recall');
        Route::post('{conversation}/claim', ClaimInboxConversationAction::class)->name('admin.inbox.conversations.claim');
        Route::post('{conversation}/transfer', TransferInboxConversationAction::class)->name('admin.inbox.conversations.transfer');
        Route::post('{conversation}/release-to-ai', ReleaseInboxConversationToAiAction::class)->name('admin.inbox.conversations.release-to-ai');
        Route::post('{conversation}/reopen', ReopenInboxConversationAction::class)->name('admin.inbox.conversations.reopen');
        Route::post('{conversation}/close', CloseInboxConversationAction::class)->name('admin.inbox.conversations.close');
        Route::put('{conversation}/visitor-locale', UpdateConversationVisitorLocaleAction::class)->name('admin.inbox.conversations.visitor-locale.update');
        // 会话标签人工增删（含历史会话）：删除写抑制墓碑，AI 重算不复打。
        Route::post('{conversation}/tags', AttachConversationTagAction::class)->name('admin.inbox.conversations.tags.attach');
        Route::delete('{conversation}/tags/{tagId}', DetachConversationTagAction::class)->whereUlid('tagId')->name('admin.inbox.conversations.tags.detach');
    });

    // AI 浮动助手：同步 ack 一轮对话，流式增量由 Go 侧推送到 Mercure topic。
    // 节流走命名 limiter（FortifyServiceProvider::configureRateLimiting 注册），
    // 按用户维度计，避免同一 NAT 公网 IP 下多个用户互相挤压配额。
    Route::post('/ai-chat/messages', SendAiAssistantMessageAction::class)
        ->middleware('throttle:ai-chat-send')
        ->name('admin.ai-chat.messages.store');
    Route::post('/ai-chat/stop', StopAiAssistantMessageAction::class)
        ->middleware('throttle:ai-chat-stop')
        ->name('admin.ai-chat.stop');

    // 快捷回复：CRUD 全员可访问，管理员维护系统共享模板。
    // search/use-and-render 走 XHR，给收件箱 composer 用。
    Route::prefix('canned-replies')->group(function () {
        Route::get('/', ShowCannedReplyListAction::class)->name('admin.canned-replies.index');
        Route::post('/', CreateCannedReplyAction::class)->name('admin.canned-replies.store');
        Route::get('/search', SearchCannedRepliesForComposerAction::class)->name('admin.canned-replies.search');
        Route::put('/{cannedReply}', UpdateCannedReplyAction::class)->whereUlid('cannedReply')->name('admin.canned-replies.update');
        Route::delete('/{cannedReply}', DeleteCannedReplyAction::class)->whereUlid('cannedReply')->name('admin.canned-replies.destroy');
        Route::post('/{cannedReply}/use-and-render', UseAndRenderCannedReplyAction::class)->whereUlid('cannedReply')->name('admin.canned-replies.use-and-render');
    });

    // 管理中心
    Route::prefix('manage')->group(function () {
        // 知识库
        Route::prefix('knowledge-bases')->group(function () {
            Route::get('/', ListKnowledgeBasesAction::class)->name('admin.manage.knowledge-bases.index');
            Route::get('/create', ShowCreateKnowledgeBasePageAction::class)->name('admin.manage.knowledge-bases.create');
            Route::post('/', CreateKnowledgeBaseAction::class)->name('admin.manage.knowledge-bases.store');
            Route::put('/settings', UpdateSystemKnowledgeSettingsAction::class)->name('admin.manage.knowledge-bases.settings.update');
            Route::get('/{knowledgeBase}/edit', ShowEditKnowledgeBasePageAction::class)->whereUlid('knowledgeBase')->name('admin.manage.knowledge-bases.edit');
            Route::put('/{knowledgeBase}', UpdateKnowledgeBaseAction::class)->whereUlid('knowledgeBase')->name('admin.manage.knowledge-bases.update');
            Route::delete('/{knowledgeBase}', DeleteKnowledgeBaseAction::class)->whereUlid('knowledgeBase')->name('admin.manage.knowledge-bases.destroy');
            Route::post('/{knowledgeBase}/recall-test', RunKnowledgeRecallTestAction::class)->whereUlid('knowledgeBase')->name('admin.manage.knowledge-bases.recall-test');

            // 文档分组
            Route::post('/{knowledgeBase}/groups', CreateKnowledgeGroupAction::class)->whereUlid('knowledgeBase')->name('admin.manage.knowledge-bases.groups.store');
            Route::put('/{knowledgeBase}/groups/{group}', UpdateKnowledgeGroupAction::class)->whereUlid('knowledgeBase')->whereUlid('group')->name('admin.manage.knowledge-bases.groups.update');
            Route::delete('/{knowledgeBase}/groups/{group}', DeleteKnowledgeGroupAction::class)->whereUlid('knowledgeBase')->whereUlid('group')->name('admin.manage.knowledge-bases.groups.destroy');

            // 文档
            Route::post('/{knowledgeBase}/documents', UploadKnowledgeDocumentAction::class)->whereUlid('knowledgeBase')->name('admin.manage.knowledge-bases.documents.store');
            Route::post('/{knowledgeBase}/documents/manual', CreateManualKnowledgeDocumentAction::class)->whereUlid('knowledgeBase')->name('admin.manage.knowledge-bases.documents.manual.store');
            Route::get('/{knowledgeBase}/documents/{document}/preview-file', StreamKnowledgeDocumentPreviewFileAction::class)->whereUlid('knowledgeBase')->whereUlid('document')->name('admin.manage.knowledge-bases.documents.preview-file');
            Route::put('/{knowledgeBase}/documents/{document}/manual', UpdateManualKnowledgeDocumentAction::class)->whereUlid('knowledgeBase')->whereUlid('document')->name('admin.manage.knowledge-bases.documents.manual.update');
            Route::put('/{knowledgeBase}/documents/{document}/group', MoveKnowledgeDocumentAction::class)->whereUlid('knowledgeBase')->whereUlid('document')->name('admin.manage.knowledge-bases.documents.move');
            Route::post('/{knowledgeBase}/documents/{document}/reindex', ReindexKnowledgeDocumentAction::class)->whereUlid('knowledgeBase')->whereUlid('document')->name('admin.manage.knowledge-bases.documents.reindex');
            Route::delete('/{knowledgeBase}/documents/{document}', DeleteKnowledgeDocumentAction::class)->whereUlid('knowledgeBase')->whereUlid('document')->name('admin.manage.knowledge-bases.documents.destroy');

            // 问答
            Route::post('/{knowledgeBase}/qa-entries', CreateKnowledgeQaEntryAction::class)->whereUlid('knowledgeBase')->name('admin.manage.knowledge-bases.qa-entries.store');
            Route::put('/{knowledgeBase}/qa-entries/{entry}', UpdateKnowledgeQaEntryAction::class)->whereUlid('knowledgeBase')->whereUlid('entry')->name('admin.manage.knowledge-bases.qa-entries.update');
            Route::put('/{knowledgeBase}/qa-entries/{entry}/group', MoveKnowledgeQaEntryAction::class)->whereUlid('knowledgeBase')->whereUlid('entry')->name('admin.manage.knowledge-bases.qa-entries.move');
            Route::delete('/{knowledgeBase}/qa-entries/{entry}', DeleteKnowledgeQaEntryAction::class)->whereUlid('knowledgeBase')->whereUlid('entry')->name('admin.manage.knowledge-bases.qa-entries.destroy');
        });

        // AI 供应商（系统内独立管理凭据与模型）
        Route::prefix('ai/providers')->group(function () {
            Route::get('/', ShowSystemAiProvidersAction::class)->name('admin.manage.ai.providers.index');
            Route::post('/', CreateAiProviderAction::class)->name('admin.manage.ai.providers.store');
            Route::put('/{provider}', UpdateAiProviderCredentialsAction::class)->name('admin.manage.ai.providers.update');
            Route::delete('/{provider}', DeleteAiProviderAction::class)->name('admin.manage.ai.providers.destroy');
            Route::post('/{provider}/check', CheckAiProviderAction::class)->name('admin.manage.ai.providers.check');
            Route::post('/{provider}/models', CreateAiModelAction::class)->name('admin.manage.ai.models.store');
            Route::put('/{provider}/models/{model}/toggle', ToggleAiModelAction::class)->name('admin.manage.ai.models.toggle');
            Route::delete('/{provider}/models/{model}', DeleteAiModelAction::class)->name('admin.manage.ai.models.destroy');
        });

        // 翻译供应商（系统独立管理，给接待页消息双向翻译提供凭据；同时刻最多一家被设为默认）
        Route::prefix('translation/providers')->group(function () {
            Route::get('/', ShowSystemTranslationProvidersAction::class)->name('admin.manage.translation.providers.index');
            Route::post('/', CreateTranslationProviderAction::class)->name('admin.manage.translation.providers.store');
            Route::post('/check', CheckTranslationProviderAction::class)->name('admin.manage.translation.providers.check-new');
            Route::put('/{provider}', UpdateTranslationProviderCredentialsAction::class)->name('admin.manage.translation.providers.update');
            Route::delete('/{provider}', DeleteTranslationProviderAction::class)->name('admin.manage.translation.providers.destroy');
            Route::delete('/{provider}/credentials', ClearTranslationProviderCredentialsAction::class)->name('admin.manage.translation.providers.clear-credentials');
            Route::post('/{provider}/check', CheckTranslationProviderAction::class)->name('admin.manage.translation.providers.check');
        });

        // MCP 服务（系统内独立管理，可供多种能力场景调用）
        Route::prefix('mcp-servers')->group(function () {
            Route::get('/', ShowSystemMcpServersAction::class)->name('admin.manage.mcp.servers.index');
            Route::post('/', CreateMcpServerAction::class)->name('admin.manage.mcp.servers.store');
            Route::post('/check', CheckMcpServerAction::class)->name('admin.manage.mcp.servers.check-unsaved');
            Route::put('/{server}', UpdateMcpServerAction::class)->name('admin.manage.mcp.servers.update');
            Route::delete('/{server}', DeleteMcpServerAction::class)->name('admin.manage.mcp.servers.destroy');
            Route::put('/{server}/toggle', ToggleMcpServerAction::class)->name('admin.manage.mcp.servers.toggle');
            Route::post('/{server}/check', CheckMcpServerAction::class)->name('admin.manage.mcp.servers.check');
            Route::post('/{server}/sync', SyncMcpServerToolsAction::class)->name('admin.manage.mcp.servers.sync');
            Route::put('/{server}/tools/{tool}/toggle', ToggleMcpToolAction::class)->whereUlid('tool')->name('admin.manage.mcp.tools.toggle');
        });

        // 接待方案：单页双栏管理（活跃 / 回收站 view），含能力包与版本历史。
        Route::prefix('reception/plans')->group(function () {
            Route::get('/', ShowReceptionPlanIndexPageAction::class)->name('admin.manage.reception.plans.index');
            Route::get('/create', ShowCreateReceptionPlanPageAction::class)->name('admin.manage.reception.plans.create');
            Route::get('/trash', ListReceptionPlanTrashAction::class)->name('admin.manage.reception.plans.trash');
            Route::post('/', CreateReceptionPlanAction::class)->name('admin.manage.reception.plans.store');
            Route::get('/{plan}', ShowReceptionPlanDetailPageAction::class)->whereUlid('plan')->name('admin.manage.reception.plans.show');
            Route::put('/{plan}', UpdateReceptionPlanAction::class)->whereUlid('plan')->name('admin.manage.reception.plans.update');
            Route::delete('/{plan}', DeleteReceptionPlanAction::class)->whereUlid('plan')->name('admin.manage.reception.plans.destroy');
            Route::put('/{plan}/restore', RestoreReceptionPlanAction::class)->whereUlid('plan')->name('admin.manage.reception.plans.restore');
        });

        // 渠道
        Route::prefix('channels')->group(function () {
            // 网站渠道
            Route::prefix('web')->group(function () {
                Route::get('/', ListWebChannelsAction::class)->name('admin.manage.channels.web.index');
                Route::get('/create', ShowCreateWebChannelPageAction::class)->name('admin.manage.channels.web.create');
                Route::get('/trash', ListWebChannelTrashAction::class)->name('admin.manage.channels.web.trash');
                Route::post('/', CreateWebChannelAction::class)->name('admin.manage.channels.web.store');
                Route::get('/{channel}', ShowWebChannelDetailPageAction::class)->whereUlid('channel')->name('admin.manage.channels.web.show');
                Route::put('/{channel}/basic', UpdateWebChannelBasicAction::class)->whereUlid('channel')->name('admin.manage.channels.web.basic.update');
                Route::put('/{channel}/visitor-interface', UpdateWebChannelVisitorInterfaceAction::class)->whereUlid('channel')->name('admin.manage.channels.web.visitor-interface.update');
                Route::put('/{channel}/widget', UpdateWebChannelWidgetAction::class)->whereUlid('channel')->name('admin.manage.channels.web.widget.update');
                Route::put('/{channel}/access', UpdateWebChannelAccessAction::class)->whereUlid('channel')->name('admin.manage.channels.web.access.update');
                Route::put('/{channel}/embed', UpdateWebChannelEmbedAction::class)->whereUlid('channel')->name('admin.manage.channels.web.embed.update');
                Route::post('/{channel}/user-token-secret', RegenerateWebChannelUserTokenSecretAction::class)->whereUlid('channel')->name('admin.manage.channels.web.user-token-secret.regenerate');
                Route::put('/{channel}/restore', RestoreWebChannelAction::class)->whereUlid('channel')->name('admin.manage.channels.web.restore');
                Route::delete('/{channel}', DeleteWebChannelAction::class)->whereUlid('channel')->name('admin.manage.channels.web.destroy');
            });

            // Telegram Bot 渠道
            Route::prefix('telegram')->group(function () {
                Route::get('/', ListTelegramChannelsAction::class)->name('admin.manage.channels.telegram.index');
                Route::get('/create', ShowCreateTelegramChannelPageAction::class)->name('admin.manage.channels.telegram.create');
                Route::get('/trash', ListTelegramChannelTrashAction::class)->name('admin.manage.channels.telegram.trash');
                Route::post('/', CreateTelegramChannelAction::class)->name('admin.manage.channels.telegram.store');
                Route::get('/{channel}', ShowTelegramChannelDetailPageAction::class)->whereUlid('channel')->name('admin.manage.channels.telegram.show');
                Route::put('/{channel}/basic', UpdateTelegramChannelBasicAction::class)->whereUlid('channel')->name('admin.manage.channels.telegram.basic.update');
                Route::put('/{channel}/token', UpdateTelegramChannelTokenAction::class)->whereUlid('channel')->name('admin.manage.channels.telegram.token.update');
                Route::post('/{channel}/webhook', RegisterTelegramWebhookAction::class)->whereUlid('channel')->name('admin.manage.channels.telegram.webhook.register');
                Route::put('/{channel}/restore', RestoreTelegramChannelAction::class)->whereUlid('channel')->name('admin.manage.channels.telegram.restore');
                Route::delete('/{channel}', DeleteTelegramChannelAction::class)->whereUlid('channel')->name('admin.manage.channels.telegram.destroy');
            });
        });

        // 标签
        Route::prefix('tags')->group(function () {
            Route::get('/', ShowTagListAction::class)->name('admin.manage.tags.index');
            Route::get('/trash', ShowTagTrashAction::class)->name('admin.manage.tags.trash');
            Route::post('/', CreateTagAction::class)->name('admin.manage.tags.store');
            Route::post('/merge', MergeTagsAction::class)->name('admin.manage.tags.merge');
            // 标签组
            Route::post('/groups', CreateTagGroupAction::class)->name('admin.manage.tags.groups.store');
            Route::put('/groups/{id}', UpdateTagGroupAction::class)->whereUlid('id')->name('admin.manage.tags.groups.update');
            Route::delete('/groups/{id}', DeleteTagGroupAction::class)->whereUlid('id')->name('admin.manage.tags.groups.destroy');
            Route::put('{id}', UpdateTagAction::class)->name('admin.manage.tags.update');
            Route::put('{id}/restore', RestoreTagAction::class)->name('admin.manage.tags.restore');
            Route::delete('{id}', DeleteTagAction::class)->name('admin.manage.tags.destroy');
            Route::get('{id}/usage', ListTagUsageAction::class)->name('admin.manage.tags.usage');
        });

        // 自定义属性
        Route::prefix('attributes')->group(function () {
            Route::get('/', ShowAttributeDefinitionListAction::class)->name('admin.manage.attributes.index');
            Route::get('/trash', ShowAttributeDefinitionTrashAction::class)->name('admin.manage.attributes.trash');
            Route::post('/', CreateAttributeDefinitionAction::class)->name('admin.manage.attributes.store');
            Route::put('reorder', ReorderAttributeDefinitionsAction::class)->name('admin.manage.attributes.reorder');
            Route::put('{id}/archive', ArchiveAttributeDefinitionAction::class)->whereUlid('id')->name('admin.manage.attributes.archive');
            Route::put('{id}/restore', RestoreAttributeDefinitionAction::class)->whereUlid('id')->name('admin.manage.attributes.restore');
            Route::put('{id}', UpdateAttributeDefinitionAction::class)->whereUlid('id')->name('admin.manage.attributes.update');
        });
    });

    // 联系人
    Route::prefix('contacts')->group(function () {
        Route::get('/trash', GetContactTrashListAction::class)->name('admin.contacts.trash');
        Route::get('/{type}/index', ShowContactListAction::class)
            ->whereIn('type', ['all', 'contacts', 'visitors'])
            ->name('admin.contacts.index');
        Route::post('/', CreateContactAction::class)->name('admin.contacts.store');
        Route::post('/merge', MergeContactsAction::class)->name('admin.contacts.merge');
        Route::get('/{id}/detail', ShowContactDetailAction::class)->name('admin.contacts.show');
        Route::put('/{id}', UpdateContactAction::class)->name('admin.contacts.update');
        Route::put('/{id}/importance', UpdateContactImportanceAction::class)->whereUlid('id')->name('admin.contacts.importance.update');
        Route::delete('/{id}', DeleteContactAction::class)->name('admin.contacts.destroy');
        Route::put('/{id}/restore', RestoreContactAction::class)->name('admin.contacts.restore');
        Route::post('/{contactId}/identities', CreateContactIdentityAction::class)->name('admin.contacts.identities.store');
        Route::put('/{contactId}/identities/{identityId}', ReplaceContactIdentityAction::class)->name('admin.contacts.identities.replace');
        Route::delete('/{contactId}/identities/{identityId}', DeleteContactIdentityAction::class)->name('admin.contacts.identities.destroy');
        Route::put('/{id}/attributes', UpdateContactAttributeValuesAction::class)->whereUlid('id')->name('admin.contacts.attributes.update');
        Route::post('/{id}/tags', AttachContactTagAction::class)->name('admin.contacts.tags.attach');
        Route::delete('/{id}/tags/{tagId}', DetachContactTagAction::class)->name('admin.contacts.tags.detach');
    });

    // 会话
    Route::prefix('/conversations')->group(function () {
        Route::get('/', ShowConversationListAction::class)->name('admin.conversations.index');
        Route::get('/{id}/detail', ShowConversationDetailAction::class)
            ->whereUlid('id')
            ->name('admin.conversations.show');
    });
});
