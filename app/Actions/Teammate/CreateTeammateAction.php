<?php

namespace App\Actions\Teammate;

use App\Actions\Attachment\AttachUploadedAttachmentsAction;
use App\Data\SystemUserContextData;
use App\Data\Teammate\FormCreateTeammateData;
use App\Enums\AttachmentPurpose;
use App\Enums\UserOnlineStatus;
use App\Enums\UserPermission;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 创建可登录后台的客服账号。
 */
class CreateTeammateAction
{
    use AsAction;

    /**
     * 新建客服账号、保存权限并绑定头像。
     */
    public function handle(User $actor, FormCreateTeammateData $data): User
    {
        Gate::forUser($actor)->authorize('user.permission', UserPermission::UsersCreate);

        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                'avatar' => null,
                'nickname' => filled($data->nickname) ? $data->nickname : null,
                'permissions' => $data->permissions,
                'online_status' => UserOnlineStatus::Online,
                'last_active_at' => null,
                'is_super_admin' => false,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();
            $this->bindUploadedAvatar($user, $data->avatar_id);

            return $user;
        });
    }

    /**
     * 接收客服创建表单并返回客服列表。
     */
    public function asController(Request $request): RedirectResponse
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $actor = User::query()->findOrFail($ctx->user_id);

        $this->handle($actor, FormCreateTeammateData::from($request));

        return redirect()->route('admin.manage.teammates.index');
    }

    /**
     * 将上传头像绑定到新客服账号。
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
