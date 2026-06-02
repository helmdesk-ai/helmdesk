<?php

namespace App\Data;

use App\Enums\SystemRole;
use App\Enums\UserOnlineStatus;
use App\Models\Attachment;
use App\Models\SystemContext;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * 当前请求里的单租户用户上下文。
 * 由 IdentifySystem 中间件共享给 Inertia，前端布局和权限按钮从这里读取当前后台用户信息。
 */
class SystemUserContextData extends Data
{
    public function __construct(
        public string $system_slug,
        public string $system_name,
        public string $system_logo_url,
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

        $role = $user->is_super_admin ? SystemRole::Owner : $user->role;
        $onlineStatus = $user->online_status instanceof UserOnlineStatus
            ? $user->online_status
            : UserOnlineStatus::from((int) $user->online_status);

        return new self(
            system_slug: 'admin',
            system_name: $generalSettings->name ?? config('app.name', 'HelmDesk'),
            system_logo_url: Attachment::query()->find($generalSettings->logo_id)?->full_url ?? asset('images/logo.png'),
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
     * 兼容旧调用签名，单租户下系统参数不再参与上下文解析。
     */
    public static function fromModels(SystemContext $systemContext, User $user): self
    {
        return self::fromUser($user);
    }

    public static function fromRequest(Request $request): self
    {
        $ctx = $request->attributes->get(self::class);

        if (! $ctx instanceof self) {
            throw new RuntimeException('System user context is not set.');
        }

        return $ctx;
    }

    public static function tryFromRequest(Request $request): ?self
    {
        $ctx = $request->attributes->get(self::class);

        return $ctx instanceof self ? $ctx : null;
    }

    public function systemContext(): SystemContext
    {
        return SystemContext::current();
    }

    public function systemSlug(): string
    {
        return $this->system_slug;
    }

    public function withShowRemoveButton(bool $showRemoveButton): self
    {
        $this->show_remove_button = $showRemoveButton;

        return $this;
    }
}
