<?php

namespace App\Actions\User;

use App\Data\User\FormDeleteProfileData;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除当前用户账号。
 */
class DeleteProfileAction
{
    use AsAction;

    public function handle(User $user): void
    {
        $user->forceDelete();
    }

    public function asController(Request $request): RedirectResponse
    {
        FormDeleteProfileData::from($request);

        $user = $request->user();

        Auth::logout();

        $this->handle($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
