<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * 处理初始化注册完成后的后台登录态切换。
 */
class RegisterResponse implements RegisterResponseContract
{
    /**
     * 把首个超级管理员切到 admin guard 并进入总后台。
     */
    public function toResponse($request): Response
    {
        /** @var Request $request */
        $user = $request->user();

        if ($user && $user->is_super_admin) {
            Auth::guard('admin')->login($user);
            Auth::guard('web')->logout();

            return redirect()->to(route('admin.home', absolute: false));
        }

        return redirect()->to(route('login', absolute: false));
    }
}
