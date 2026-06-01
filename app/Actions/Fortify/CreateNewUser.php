<?php

namespace App\Actions\Fortify;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Settings\GeneralSettings;
use App\Support\LocalePreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * 处理 Fortify 注册流程，创建用户并初始化个人工作区。
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private readonly GeneralSettings $settings,
    ) {}

    /**
     * 校验注册输入并创建用户。
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! $this->settings->allow_registration) {
            throw ValidationException::withMessages([
                'email' => __('auth.registration_disabled'),
            ]);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'unique:users', 'unique:workspaces,path', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
            'locale' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ])->validate();

        return DB::transaction(function () use ($input) {
            $isFirstUser = User::query()->count() === 0;

            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'locale' => LocalePreference::normalizeFrontend(
                    $input['locale'] ?? LocalePreference::frontendFromLaravel(app()->getLocale())
                ),
                'timezone' => $input['timezone'] ?? null,
                'is_super_admin' => $isFirstUser,
            ]);

            if ($isFirstUser) {
                return $user;
            }

            $workspace = Workspace::query()->create([
                'name' => mb_substr($input['name'], 0, 5)."'s Workspace",
                'owner_id' => $user->id,
            ]);

            $user->workspaces()->attach($workspace->id, ['role' => WorkspaceRole::Owner]);

            return $user;
        });
    }
}
