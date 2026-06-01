<?php

namespace App\Services\CannedReply;

use App\Models\CannedReply;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;

/**
 * 快捷回复模版的权限判断服务。
 * 个人模版仅作者可改；工作区共享模版需要 canAccessManageCenter；
 * 所有工作区成员都可读到自己可见的模版（个人 + 工作区共享）。
 */
class CannedReplyPermission
{
    /**
     * 当前用户能否管理（创建 / 编辑 / 删除）工作区共享模版。
     */
    public function canManageWorkspaceShared(Workspace $workspace, User $user): bool
    {
        return Gate::forUser($user)->allows('workspace.canAccessManageCenter', [$workspace]);
    }

    /**
     * 判断用户对某条模版是否可编辑。
     */
    public function canEdit(CannedReply $reply, Workspace $workspace, User $user): bool
    {
        if ((string) $reply->workspace_id !== (string) $workspace->id) {
            return false;
        }

        if ($reply->isOwnedBy($user)) {
            return true;
        }

        if ($reply->isWorkspaceShared()) {
            return $this->canManageWorkspaceShared($workspace, $user);
        }

        return false;
    }

    /**
     * 删除策略与编辑相同。
     */
    public function canDelete(CannedReply $reply, Workspace $workspace, User $user): bool
    {
        return $this->canEdit($reply, $workspace, $user);
    }

    /**
     * 当前用户能看到的模版（自己个人 + 工作区共享）。
     */
    public function canView(CannedReply $reply, Workspace $workspace, User $user): bool
    {
        if ((string) $reply->workspace_id !== (string) $workspace->id) {
            return false;
        }

        return $reply->isWorkspaceShared() || $reply->isOwnedBy($user);
    }
}
