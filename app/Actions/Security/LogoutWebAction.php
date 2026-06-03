<?php

namespace App\Actions\Security;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 退出当前登录状态。
 */
class LogoutWebAction
{
    use AsAction;

    public function asController(Request $request): RedirectResponse
    {
        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->regenerateToken();
        }

        return redirect()->to(route('home', absolute: false));
    }
}
