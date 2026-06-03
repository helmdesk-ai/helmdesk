<?php

namespace App\Data\Teammate;

use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * 客服编辑页表单初始数据。
 * 由 resources/js/pages/teammates/Edit.vue 消费，用于填充账号与权限字段。
 */
class EditTeammateData extends Data
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $avatar,
        public ?string $nickname,
        public array $permissions,
        public bool $two_factor_enabled,
    ) {}

    /**
     * 从用户模型构造编辑页表单数据。
     */
    public static function fromModel(User $user): self
    {
        return new self(
            id: (string) $user->id,
            name: $user->name,
            email: $user->email,
            avatar: filled($user->avatar) ? $user->avatar : null,
            nickname: filled($user->nickname) ? (string) $user->nickname : null,
            permissions: array_values(array_filter(array_map('strval', $user->permissions ?? []))),
            two_factor_enabled: filled($user->two_factor_confirmed_at),
        );
    }
}
