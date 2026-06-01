<?php

namespace App\Data;

use App\Enums\UserOnlineStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * 当前请求里的工作区上下文。
 * 由 IdentifyWorkspace 中间件共享给 Inertia，前端布局和权限按钮都从这里读取当前工作区信息。
 */
class WorkspaceUserContextData extends Data
{
    private ?Workspace $workspaceModel = null;

    public function __construct(
        public string $workspace_id,
        public string $workspace_slug,
        public string $workspace_name,
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

    public static function fromModels(Workspace $workspace, User $user): self
    {
        $roleValue = $user->pivot?->role ?? $workspace->users()->whereKey($user->id)->value('user_workspace.role');
        $role = WorkspaceRole::from((string) $roleValue);

        $pivotNickname = $user->pivot?->nickname ?? $workspace->users()->whereKey($user->id)->value('user_workspace.nickname');
        $pivotOnlineStatus = $user->pivot?->online_status ?? $workspace->users()->whereKey($user->id)->value('user_workspace.online_status');
        $pivotLastActiveAt = $user->pivot?->last_active_at ?? $workspace->users()->whereKey($user->id)->value('user_workspace.last_active_at');
        if ($pivotOnlineStatus === null) {
            throw new RuntimeException('Workspace user online status is not set.');
        }

        $onlineStatusEnum = UserOnlineStatus::from((int) $pivotOnlineStatus);
        $lastActiveAt = filled($pivotLastActiveAt) ? Carbon::parse($pivotLastActiveAt)->toIso8601String() : null;

        return new self(
            workspace_id: (string) $workspace->id,
            workspace_slug: $workspace->slug,
            workspace_name: $workspace->name,
            user_id: (string) $user->id,
            user_name: $user->name,
            user_email: $user->email,
            user_avatar: filled($user->avatar) ? $user->avatar : null,
            user_online_status: EnumOptionData::fromEnum($onlineStatusEnum),
            user_nickname: filled($pivotNickname) ? (string) $pivotNickname : null,
            user_last_active_at: $lastActiveAt,
            role: EnumOptionData::fromEnum($role),
        );
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
        if ($this->workspaceModel instanceof Workspace) {
            return $this->workspaceModel;
        }

        $this->workspaceModel = Workspace::query()->findOrFail($this->workspace_id);

        return $this->workspaceModel;
    }

    public function workspaceId(): string
    {
        return $this->workspace_id;
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
