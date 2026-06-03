<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * 邮箱验证成功后按用户身份跳转到系统后台或系统入口。
 */
class VerifyEmailResponse implements VerifyEmailResponseContract
{
    /**
     * 生成邮箱验证完成后的 Fortify 响应。
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        /** @var Request $request */
        $user = $request->user();

        if ($user?->is_super_admin) {
            return redirect()->to(route('admin.home', absolute: false).'?verified=1');
        }

        return $request->wantsJson()
            ? response()->json('', 204)
            : redirect()->intended(Fortify::redirects('email-verification').'?verified=1');
    }
}
