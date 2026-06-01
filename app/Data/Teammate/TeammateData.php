<?php

namespace App\Data\Teammate;

use App\Enums\UserOnlineStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * 客服成员数据。
 * 由后端组装后传给 resources/js/pages/teammate/List.vue、Create.vue、Edit.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class TeammateData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $nickname,
        public ?string $avatar,
        public string $email,
        public WorkspaceRole $role,
        public string $role_label,
        public UserOnlineStatus $online_status,
    ) {}

    public static function fromModel(User $user): self
    {
        $role = WorkspaceRole::from((string) ($user->pivot?->role ?? ''));
        $onlineStatusValue = $user->pivot?->online_status;
        if ($onlineStatusValue === null) {
            throw new RuntimeException('Workspace teammate online status is not set.');
        }

        $onlineStatus = UserOnlineStatus::from((int) $onlineStatusValue);

        return new self(
            id: (string) $user->id,
            name: $user->name,
            nickname: filled($user->pivot?->nickname) ? (string) $user->pivot->nickname : null,
            avatar: filled($user->avatar) ? $user->avatar : null,
            email: $user->email,
            role: $role,
            role_label: $role->label(),
            online_status: $onlineStatus,
        );
    }
}
