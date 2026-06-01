<?php

namespace App\Data\CurrentWorkspace;

use App\Data\Workspace\WorkspaceOwnerData;
use App\Models\Workspace;
use Spatie\LaravelData\Data;

/**
 * 工作区详情数据。
 * 由后端组装后传给 resources/js/pages/currentWorkspace/Index.vue、Create.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class WorkspaceDetailData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $slug,
        public string $created_at,
        public int $members_count,
        public ?WorkspaceOwnerData $owner,
    ) {}

    public static function fromModel(Workspace $workspace, ?int $membersCount = null): self
    {
        return new self(
            id: $workspace->id,
            name: $workspace->name,
            slug: $workspace->slug,
            created_at: $workspace->created_at?->toIso8601String() ?? '',
            members_count: $membersCount ?? (int) ($workspace->users_count ?? 0),
            owner: $workspace->owner ? WorkspaceOwnerData::fromModel($workspace->owner) : null,
        );
    }
}
