<?php

namespace App\Data\Workspace;

use App\Models\Workspace;
use Spatie\LaravelData\Data;

/**
 * 工作区数据。
 * 由后端组装后传给 resources/js/pages/admin/workspace/*，用于页面展示、抽屉详情或局部交互状态。
 */
class WorkspaceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $logo_id,
        public string $logo_url,
        public ?string $owner_id,
        public string $created_at,
        public int $members_count,
        public ?WorkspaceOwnerData $owner,
    ) {}

    public static function fromModel(Workspace $workspace): self
    {
        return new self(
            id: $workspace->id,
            name: $workspace->name,
            slug: $workspace->slug,
            logo_id: $workspace->logo_id,
            logo_url: $workspace->logo_url,
            owner_id: $workspace->owner_id,
            created_at: $workspace->created_at?->toIso8601String() ?? '',
            members_count: (int) ($workspace->users_count ?? 0),
            owner: $workspace->owner ? WorkspaceOwnerData::fromModel($workspace->owner) : null,
        );
    }
}
