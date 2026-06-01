<?php

namespace App\Data\User;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 更新用户表单数据。
 * 来自 resources/js/pages/admin/user/* 和 pages/settings/* 的编辑表单提交，后端用它校验并保存用户配置。
 */
class FormUpdateUserData extends Data
{
    /**
     * 承载系统编辑用户表单提交的账号、密码和头像附件。
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $password = null,
        public ?string $avatar_id = null,
    ) {}

    /**
     * 返回编辑用户表单验证规则。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        $userId = request()->route('id');

        return [
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'avatar_id' => ['nullable', 'string', 'max:26'],
        ];
    }
}
