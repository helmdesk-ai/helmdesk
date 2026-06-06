<?php

namespace App\Actions\User;

use App\Data\User\FormUpdateMyOnlineStatusData;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新当前用户在线状态。
 */
class UpdateMyOnlineStatusAction
{
    use AsAction;

    /**
     * 保存当前用户在线状态。
     */
    public function handle(string $userId, FormUpdateMyOnlineStatusData $data): void
    {
        $user = User::query()->findOrFail($userId);

        $user->forceFill([
            'online_status' => $data->online_status,
        ])->save();
    }

    /**
     * 接收当前用户在线状态更新请求。
     */
    public function asController(Request $request): RedirectResponse
    {
        $data = FormUpdateMyOnlineStatusData::from($request);
        $this->handle((string) $request->user()->id, $data);

        return back();
    }
}
