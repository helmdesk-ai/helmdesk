<?php

namespace App\Http\Middleware;

use App\Actions\User\TouchSystemUserLastActiveAtAction;
use App\Data\AiRuntime\AiModelOptionData;
use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Services\AiRuntime\AiModelResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 共享单租户后台上下文。
 */
class IdentifySystem
{
    /**
     * 注入模型选项解析器和成员活跃时间刷新动作。
     */
    public function __construct(
        private AiModelResolver $modelResolver,
        private TouchSystemUserLastActiveAtAction $touchSystemUserLastActiveAtAction,
    ) {}

    /**
     * 处理传入请求。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $this->touchSystemUserLastActiveAtAction->handle((string) $user->id);

        $systemUserContext = SystemUserContextData::fromUser($user->fresh());
        $systemContext = $systemUserContext->systemContext();

        $request->attributes->set(SystemUserContextData::class, $systemUserContext);
        Inertia::share('systemUserContext', $systemUserContext->toArray());
        Inertia::share('canAccessUsers', Gate::allows('user.permission', UserPermission::UsersView));
        Inertia::share('canAccessContacts', Gate::allows('user.permission', UserPermission::ContactsView));
        Inertia::share('canAccessConversations', Gate::allows('user.permission', UserPermission::ConversationsView));
        Inertia::share('canAccessTags', Gate::allows('user.permission', UserPermission::TagsView));
        Inertia::share('canAccessAttributes', Gate::allows('user.permission', UserPermission::AttributesView));
        Inertia::share('canAccessCannedReplies', Gate::allows('user.permission', UserPermission::CannedRepliesView));
        Inertia::share('canAccessKnowledgeBases', Gate::allows('user.permission', UserPermission::KnowledgeBasesView));
        Inertia::share('canAccessReceptionPlans', Gate::allows('user.permission', UserPermission::ReceptionPlansView));
        Inertia::share('canAccessChannels', Gate::allows('user.permission', UserPermission::ChannelsView));
        Inertia::share('canManageSystemSettings', Gate::allows('user.permission', UserPermission::SystemSettingsView));
        Inertia::share(
            'aiAssistantLlmModelOptions',
            array_map(
                fn (AiModelOptionData $option): array => $option->toArray(),
                $this->modelResolver->getActiveLlmModelOptions($systemContext),
            ),
        );

        return $next($request);
    }
}
