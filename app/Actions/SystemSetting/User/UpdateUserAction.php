<?php

namespace App\Actions\SystemSetting\User;

use App\Actions\Attachment\AttachUploadedAttachmentsAction;
use App\Actions\Attachment\DeleteAttachmentAction;
use App\Data\User\FormUpdateUserData;
use App\Enums\AttachmentPurpose;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新系统用户资料和超级管理员状态。
 */
class UpdateUserAction
{
    use AsAction;

    /**
     * 更新后台用户资料、密码和头像附件绑定。
     */
    public function handle(string $id, FormUpdateUserData $data): void
    {
        DB::transaction(function () use ($id, $data): void {
            $user = User::query()
                ->where('is_super_admin', false)
                ->findOrFail($id);
            $originalAvatar = $user->avatarAttachment()->first();

            $user->update([
                'name' => $data->name,
                'email' => $data->email,
            ]);

            if (filled($data->password)) {
                $user->update([
                    'password' => $data->password,
                ]);
            }

            $user->refresh();
            $this->syncUploadedAvatar($user, $originalAvatar, $data->avatar_id);
        });
    }

    /**
     * 接收编辑用户表单并返回用户列表。
     */
    public function asController(Request $request, string $id): RedirectResponse
    {
        $data = FormUpdateUserData::from($request);
        $this->handle($id, $data);

        return redirect()->route('admin.users.index');
    }

    /**
     * 同步用户头像附件绑定，并清理被替换的旧附件。
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
