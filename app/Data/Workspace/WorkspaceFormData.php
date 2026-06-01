<?php

namespace App\Data\Workspace;

use App\Models\Workspace;
use Spatie\LaravelData\Data;

/**
 * 工作区数据。
 * 由后端组装后传给 resources/js/pages/admin/workspace/*，用于页面展示、抽屉详情或局部交互状态。
 */
class WorkspaceFormData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $slug,
        public ?string $logo_id,
        public ?string $logo_url,
        public ?string $owner_id,
    ) {}

    public static function fromModel(Workspace $workspace): self
    {
        return new self(
            id: (string) $workspace->id,
            name: $workspace->name,
            slug: $workspace->slug,
            logo_id: filled($workspace->logo_id) ? (string) $workspace->logo_id : null,
            logo_url: filled($workspace->logo_url) ? (string) $workspace->logo_url : null,
            owner_id: filled($workspace->owner_id) ? (string) $workspace->owner_id : null,
        );
    }
}
