<?php

namespace App\Actions\SystemSetting\User;

use App\Actions\Attachment\AttachUploadedAttachmentsAction;
use App\Data\User\FormCreateUserData;
use App\Enums\AttachmentPurpose;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 在系统设置中创建后台用户。
 */
class CreateUserAction
{
    use AsAction;

    /**
     * 创建后台用户并绑定新上传头像。
     */
    public function handle(FormCreateUserData $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data->name,
                'email' => $data->email,
                'avatar' => null,
                'password' => $data->password,
                'is_super_admin' => false,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $this->bindUploadedAvatar($user, $data->avatar_id);

            return $user;
        });
    }

    /**
     * 接收新增用户表单并返回用户列表。
     */
    public function asController(Request $request): RedirectResponse
    {
        $data = FormCreateUserData::from($request);
        $this->handle($data);

        return redirect()->route('admin.users.index');
    }

    /**
     * 将新上传的头像附件绑定到用户模型。
     */
    private function bindUploadedAvatar(User $user, ?string $attachmentId): void
    {
        if (! filled($attachmentId)) {
            return;
        }

        $attachment = AttachUploadedAttachmentsAction::run($user, $attachmentId, null, null, null, [AttachmentPurpose::Avatar]);

        if ($attachment instanceof Attachment) {
            $user->update(['avatar' => $attachment->full_url]);
        }
    }
}
