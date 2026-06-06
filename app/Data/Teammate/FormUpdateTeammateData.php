<?php

namespace App\Data\Teammate;

use App\Enums\UserPermission;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 客服编辑表单数据。
 * 来自 resources/js/pages/teammates/Edit.vue，用于更新客服账号资料、密码和权限。
 */
class FormUpdateTeammateData extends Data
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $password,
        public ?string $nickname,
        public array $permissions = [],
        public ?string $avatar_id = null,
    ) {}

    /**
     * 返回客服编辑表单验证规则。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        $teammateId = request()->route('teammate');

        return [
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($teammateId)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(UserPermission::values())],
            'avatar_id' => ['nullable', 'string', 'max:26'],
        ];
    }
}
