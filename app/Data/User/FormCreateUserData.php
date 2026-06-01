<?php

namespace App\Data\User;

use Spatie\LaravelData\Data;

/**
 * 创建用户表单数据。
 * 来自 resources/js/pages/admin/user/* 和 pages/settings/* 的新增表单提交，后端用它做校验并写入用户相关记录。
 */
class FormCreateUserData extends Data
{
    /**
     * 承载系统新增用户表单提交的账号、密码和头像附件。
     */
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public ?string $avatar_id = null,
    ) {}

    /**
     * 返回新增用户表单验证规则。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'avatar_id' => ['nullable', 'string', 'max:26'],
        ];
    }
}
