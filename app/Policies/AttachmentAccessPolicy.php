<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\AttachmentUpload;
use App\Models\User;
use App\Services\Storage\AttachmentAccessContext;

/**
 * 判断访问上下文是否拥有附件上传意图的控制权。
 */
class AttachmentAccessPolicy
{
    /**
     * 已认证用户在自己的工作区里、或访客 token 与上传记录绑定时，允许继续操作上传。
     */
    public function canControlUpload(AttachmentAccessContext $context, AttachmentUpload $upload): bool
    {
        $upload->loadMissing('attachment');

        if (filled($upload->created_by_user_id)) {
            foreach ($context->users as $user) {
                if ((string) $upload->created_by_user_id !== (string) $user->id) {
                    continue;
                }

                return $upload->attachment->workspace_id === null
                    || $this->userCanAccess($user, $upload->attachment);
            }
        }

        if (! filled($upload->session_token_hash)) {
            return false;
        }

        foreach ($context->visitorTokens() as $token) {
            if (hash_equals($upload->session_token_hash, hash('sha256', $token))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断用户是否可以访问附件所属的工作区。
     */
    private function userCanAccess(User $user, Attachment $attachment): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        if ($attachment->workspace_id === null) {
            return false;
        }

        return $user->workspaces()
            ->where('workspaces.id', $attachment->workspace_id)
            ->exists();
    }
}
