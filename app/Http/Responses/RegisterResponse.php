<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * 处理初始化注册完成后的后台跳转。
 */
class RegisterResponse implements RegisterResponseContract
{
    /**
     * 首个超级管理员注册完成后进入总后台。
     */
    public function toResponse($request): Response
    {
        /** @var Request $request */
        $user = $request->user();

        if ($user?->is_super_admin) {
            return redirect()->to(route('admin.home', absolute: false));
        }

        return redirect()->to(route('login', absolute: false));
    }
}
