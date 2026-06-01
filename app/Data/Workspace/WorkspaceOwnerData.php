<?php

namespace App\Data\Workspace;

use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * 工作区负责人数据。
 * 由后端组装后传给 resources/js/pages/admin/workspace/*，用于页面展示、抽屉详情或局部交互状态。
 */
class WorkspaceOwnerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
        );
    }
}
