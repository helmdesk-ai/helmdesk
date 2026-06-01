<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): Response
    {
        /** @var Request $request */
        $user = $request->user();

        if ($user && $user->is_super_admin) {
            Auth::guard('admin')->login($user);
            Auth::guard('web')->logout();

            return redirect()->to(route('admin.home', absolute: false));
        }

        return redirect()->to(route('dashboard', absolute: false));
    }
}
