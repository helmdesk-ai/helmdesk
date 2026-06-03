<?php

namespace App\Data;

use App\Enums\UserOnlineStatus;
use App\Models\SystemContext;
use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * 当前请求里的单租户用户上下文。
 * 由 IdentifySystem 中间件共享给 Inertia：前端用 system_slug 做本地存储命名空间、用 user_online_status 渲染在线状态。
 * 当前用户基本信息前端走 auth.user，后端 Action 走 $request->user()，不在此重复下发。
 */
class SystemUserContextData extends Data
{
    public function __construct(
        public string $system_slug,
        public EnumOptionData $user_online_status,
    ) {}

    /**
     * 从当前登录用户构造后台上下文。
     */
    public static function fromUser(User $user): self
    {
        $onlineStatus = $user->online_status instanceof UserOnlineStatus
            ? $user->online_status
            : UserOnlineStatus::from((int) $user->online_status);

        return new self(
            system_slug: 'admin',
            user_online_status: EnumOptionData::fromEnum($onlineStatus),
        );
    }

    /**
     * 从请求属性读取当前后台用户上下文。
     */
    public static function fromRequest(Request $request): self
    {
        $ctx = $request->attributes->get(self::class);

        if (! $ctx instanceof self) {
            throw new RuntimeException('System user context is not set.');
        }

        return $ctx;
    }

    /**
     * 从请求属性尝试读取当前后台用户上下文。
     */
    public static function tryFromRequest(Request $request): ?self
    {
        $ctx = $request->attributes->get(self::class);

        return $ctx instanceof self ? $ctx : null;
    }

    /**
     * 返回当前单租户后台上下文。
     */
    public function systemContext(): SystemContext
    {
        return SystemContext::current();
    }
}
