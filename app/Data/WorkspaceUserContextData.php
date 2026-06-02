<?php

namespace App\Data;

use App\Enums\UserOnlineStatus;
use App\Enums\WorkspaceRole;
use App\Models\Attachment;
use App\Models\User;
use App\Models\Workspace;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * 当前请求里的单租户用户上下文。
 * 由 IdentifyWorkspace 中间件共享给 Inertia，前端布局和权限按钮从这里读取当前后台用户信息。
 */
class WorkspaceUserContextData extends Data
{
    public function __construct(
        public string $workspace_slug,
        public string $workspace_name,
        public string $workspace_logo_url,
        public string $user_id,
        public string $user_name,
        public string $user_email,
        public EnumOptionData $user_online_status,
        public EnumOptionData $role,
        public bool $show_remove_button = true,
        public ?string $user_nickname = null,
        public ?string $user_last_active_at = null,
        public ?string $user_avatar = null,
    ) {}

    /**
     * 从当前登录用户构造后台上下文。
     */
    public static function fromUser(User $user): self
    {
        /** @var GeneralSettings $generalSettings */
        $generalSettings = app(GeneralSettings::class);
        $generalSettings->refresh();

        $role = $user->is_super_admin ? WorkspaceRole::Owner : $user->role;
        $onlineStatus = $user->online_status instanceof UserOnlineStatus
            ? $user->online_status
            : UserOnlineStatus::from((int) $user->online_status);

        return new self(
            workspace_slug: 'admin',
            workspace_name: $generalSettings->name ?? config('app.name', 'HelmDesk'),
            workspace_logo_url: Attachment::query()->find($generalSettings->logo_id)?->full_url ?? asset('images/logo.png'),
            user_id: (string) $user->id,
            user_name: $user->name,
            user_email: $user->email,
            user_avatar: filled($user->avatar) ? $user->avatar : null,
            user_online_status: EnumOptionData::fromEnum($onlineStatus),
            user_nickname: filled($user->nickname) ? (string) $user->nickname : null,
            user_last_active_at: $user->last_active_at?->toIso8601String(),
            role: EnumOptionData::fromEnum($role),
        );
    }

    /**
     * 兼容旧调用签名，单租户下工作区参数不再参与上下文解析。
     */
    public static function fromModels(Workspace $workspace, User $user): self
    {
        return self::fromUser($user);
    }

    public static function fromRequest(Request $request): self
    {
        $ctx = $request->attributes->get(self::class);

        if (! $ctx instanceof self) {
            throw new RuntimeException('Workspace user context is not set.');
        }

        return $ctx;
    }

    public static function tryFromRequest(Request $request): ?self
    {
        $ctx = $request->attributes->get(self::class);

        return $ctx instanceof self ? $ctx : null;
    }

    public function workspace(): Workspace
    {
        return Workspace::current();
    }

    public function workspaceSlug(): string
    {
        return $this->workspace_slug;
    }

    public function withShowRemoveButton(bool $showRemoveButton): self
    {
        $this->show_remove_button = $showRemoveButton;

        return $this;
    }
}
