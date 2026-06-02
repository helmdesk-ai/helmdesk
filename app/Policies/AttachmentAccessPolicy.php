<?php

namespace App\Policies;

use App\Models\AttachmentUpload;
use App\Services\Storage\AttachmentAccessContext;

/**
 * 判断访问上下文是否拥有附件上传意图的控制权。
 */
class AttachmentAccessPolicy
{
    /**
     * 已认证用户是上传创建者、或访客 token 与上传记录绑定时，允许继续操作上传。
     */
    public function canControlUpload(AttachmentAccessContext $context, AttachmentUpload $upload): bool
    {
        $upload->loadMissing('attachment');

        if (filled($upload->created_by_user_id)) {
            foreach ($context->users as $user) {
                if ((string) $upload->created_by_user_id !== (string) $user->id) {
                    continue;
                }

                return true;
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
}
