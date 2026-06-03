<?php

namespace App\Data;

use App\Enums\UserOnlineStatus;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * 当前登录用户的单租户上下文，仅由 IdentifySystem 中间件构造并共享给 Inertia：
 * 前端用 system_slug 做本地存储命名空间、用 user_online_status 渲染在线状态。
 * 用户基本信息前端走 auth.user、后端走 $request->user()，系统运行时上下文走 SystemContext::current()，均不在此重复承载。
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
}
