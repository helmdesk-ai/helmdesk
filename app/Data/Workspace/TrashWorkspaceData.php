<?php

namespace App\Data\Workspace;

use App\Models\Workspace;
use Spatie\LaravelData\Data;

/**
 * 回收站工作区数据。
 * 显示在 resources/js/pages/admin/workspace/* 的回收站列表里，用于恢复、彻底删除和基础信息展示。
 */
class TrashWorkspaceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $slug,
        public string $created_at,
        public ?string $deleted_at,
        public int $members_count,
        public ?WorkspaceOwnerData $owner,
    ) {}

    public static function fromModel(Workspace $workspace): self
    {
        return new self(
            id: $workspace->id,
            name: $workspace->name,
            slug: $workspace->slug,
            created_at: $workspace->created_at?->toIso8601String() ?? '',
            deleted_at: $workspace->deleted_at?->toIso8601String(),
            members_count: (int) ($workspace->users_count ?? 0),
            owner: $workspace->owner ? WorkspaceOwnerData::fromModel($workspace->owner) : null,
        );
    }
}
