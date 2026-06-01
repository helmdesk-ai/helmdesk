<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        /** @var Request $request */
        $user = $request->user();

        if ($user && $user->is_super_admin) {
            Auth::guard('admin')->login($user, $request->boolean('remember'));
            Auth::guard('web')->logout();

            return redirect()->to(route('admin.home', absolute: false));
        }

        return redirect()->to(route('dashboard', absolute: false));
    }
}
