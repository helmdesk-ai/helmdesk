<?php

namespace App\Actions\Teammate;

use App\Actions\Attachment\AttachUploadedAttachmentsAction;
use App\Actions\Attachment\DeleteAttachmentAction;
use App\Data\Teammate\FormUpdateTeammateData;
use App\Enums\AttachmentPurpose;
use App\Enums\UserPermission;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新客服账号资料和权限。
 */
class UpdateTeammateAction
{
    use AsAction;

    /**
     * 保存客服资料、密码、头像和权限变更。
     */
    public function handle(User $actor, string $id, FormUpdateTeammateData $data): void
    {
        Gate::forUser($actor)->authorize('user.permission', UserPermission::UsersEdit);

        DB::transaction(function () use ($actor, $id, $data): void {
            $user = User::query()
                ->where('is_super_admin', false)
                ->findOrFail($id);

            Gate::forUser($actor)->authorize('users.updateProfile', $user);

            $originalAvatar = $user->avatarAttachment()->first();
            $user->update([
                'name' => $data->name,
                'email' => $data->email,
                'nickname' => filled($data->nickname) ? $data->nickname : null,
                'permissions' => $data->permissions,
            ]);

            if (filled($data->password)) {
                $user->update(['password' => $data->password]);
            }

            $user->refresh();
            $this->syncUploadedAvatar($user, $originalAvatar, $data->avatar_id);
        });
    }

    /**
     * 接收客服编辑表单并返回客服列表。
     */
    public function asController(Request $request, string $teammate): RedirectResponse
    {
        $actor = $request->user();

        $this->handle($actor, $teammate, FormUpdateTeammateData::from($request));

        return redirect()->route('admin.manage.teammates.index');
    }

    /**
     * 同步客服头像附件并清理被替换的旧头像。
     */
    private function syncUploadedAvatar(User $user, ?Attachment $originalAvatar, ?string $nextAttachmentId): void
    {
        if (! filled($nextAttachmentId)) {
            return;
        }

        if ($originalAvatar !== null && (string) $originalAvatar->id === $nextAttachmentId) {
            return;
        }

        $attachment = AttachUploadedAttachmentsAction::run($user, $nextAttachmentId, null, null, null, [AttachmentPurpose::Avatar]);

        if ($attachment instanceof Attachment) {
            $user->update(['avatar' => $attachment->full_url]);
        }

        if ($originalAvatar !== null) {
            DeleteAttachmentAction::run($originalAvatar);
        }
    }
}
