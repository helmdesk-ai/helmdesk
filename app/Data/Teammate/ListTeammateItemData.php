<?php

namespace App\Data\Teammate;

use App\Enums\UserOnlineStatus;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * 客服列表项数据。
 * 由 resources/js/pages/teammates/Index.vue 消费，用于展示账号、权限数量和操作状态。
 */
class ListTeammateItemData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $avatar,
        public ?string $nickname,
        public int $permission_count,
        public UserOnlineStatus $online_status,
        public string $online_status_label,
        public ?string $last_active_at,
        public bool $two_factor_enabled,
        public bool $can_edit,
        public bool $can_delete,
        public bool $can_reset_two_factor,
    ) {}

    /**
     * 从用户模型构造客服列表项。
     */
    public static function fromModel(User $user, bool $canEdit, bool $canDelete, bool $canResetTwoFactor): self
    {
        $onlineStatus = $user->online_status instanceof UserOnlineStatus
            ? $user->online_status
            : UserOnlineStatus::from((int) $user->online_status);

        return new self(
            id: (string) $user->id,
            name: $user->name,
            email: $user->email,
            avatar: filled($user->avatar) ? $user->avatar : null,
            nickname: filled($user->nickname) ? (string) $user->nickname : null,
            permission_count: count($user->permissions ?? []),
            online_status: $onlineStatus,
            online_status_label: $onlineStatus->label(),
            last_active_at: $user->last_active_at?->toIso8601String(),
            two_factor_enabled: filled($user->two_factor_confirmed_at),
            can_edit: $canEdit,
            can_delete: $canDelete,
            can_reset_two_factor: $canResetTwoFactor,
        );
    }
}
