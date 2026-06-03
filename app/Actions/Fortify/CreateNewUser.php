<?php

namespace App\Actions\Fortify;

use App\Enums\UserOnlineStatus;
use App\Models\User;
use App\Services\Localization\LocalePreference;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * 处理系统初始化注册，只允许创建第一个超级管理员。
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * 校验注册输入并创建首个超级管理员。
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (User::query()->exists()) {
            throw ValidationException::withMessages([
                'email' => __('auth.registration_disabled'),
            ]);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'unique:users', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
            'locale' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ])->validate();

        return User::query()->create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'locale' => LocalePreference::normalizeFrontend(
                $input['locale'] ?? LocalePreference::frontendFromLaravel(app()->getLocale())
            ),
            'timezone' => $input['timezone'] ?? null,
            'permissions' => [],
            'online_status' => UserOnlineStatus::Online->value,
            'last_active_at' => now(),
            'is_super_admin' => true,
        ]);
    }
}
