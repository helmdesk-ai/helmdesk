<?php

namespace App\Actions\User;

use App\Data\User\FormUpdateProfileData;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新当前用户姓名、邮箱、头像和偏好设置。
 */
class UpdateProfileAction
{
    use AsAction;

    /**
     * 更新用户个人资料并在邮箱变化时清空验证时间。
     */
    public function handle(User $user, FormUpdateProfileData $data): void
    {
        $user->fill($data->toArray());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
    }

    /**
     * 处理个人资料更新请求并返回设置页。
     */
    public function asController(Request $request): RedirectResponse
    {
        $this->handle($request->user(), FormUpdateProfileData::from($request));

        return to_route('settings.profile.edit');
    }
}
