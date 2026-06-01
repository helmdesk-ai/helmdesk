<?php

namespace App\Data\CurrentWorkspace;

use App\Data\EnumOptionData;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * 工作区成员数据。
 * 由后端组装后传给 resources/js/pages/currentWorkspace/Index.vue、Create.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class WorkspaceMemberData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?EnumOptionData $role,
        public ?string $joined_at,
        public ?string $deleted_at,
    ) {}

    public static function fromModel(User $user): self
    {
        $roleValue = $user->pivot?->role;
        $role = $roleValue === null ? null : WorkspaceRole::from((string) $roleValue);

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: $role ? EnumOptionData::fromEnum($role) : null,
            joined_at: $user->pivot?->created_at?->toIso8601String(),
            deleted_at: $user->deleted_at?->toIso8601String(),
        );
    }
}
