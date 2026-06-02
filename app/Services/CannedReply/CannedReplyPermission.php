<?php

namespace App\Services\CannedReply;

use App\Models\CannedReply;
use App\Models\SystemContext;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * 快捷回复模版的权限判断服务。
 * 个人模版仅作者可改；系统共享模版需要 canAccessManageCenter；
 * 所有后台成员都可读到自己可见的模版（个人 + 系统共享）。
 */
class CannedReplyPermission
{
    /**
     * 当前用户能否管理（创建 / 编辑 / 删除）系统共享模版。
     */
    public function canManageSystemShared(SystemContext $systemContext, User $user): bool
    {
        return Gate::forUser($user)->allows('admin.canAccessManageCenter', [$systemContext]);
    }

    /**
     * 判断用户对某条模版是否可编辑。
     */
    public function canEdit(CannedReply $reply, SystemContext $systemContext, User $user): bool
    {
        if ($reply->isOwnedBy($user)) {
            return true;
        }

        if ($reply->isSystemShared()) {
            return $this->canManageSystemShared($systemContext, $user);
        }

        return false;
    }

    /**
     * 删除策略与编辑相同。
     */
    public function canDelete(CannedReply $reply, SystemContext $systemContext, User $user): bool
    {
        return $this->canEdit($reply, $systemContext, $user);
    }

    /**
     * 当前用户能看到的模版（自己个人 + 系统共享）。
     */
    public function canView(CannedReply $reply, SystemContext $systemContext, User $user): bool
    {
        return $reply->isSystemShared() || $reply->isOwnedBy($user);
    }
}
