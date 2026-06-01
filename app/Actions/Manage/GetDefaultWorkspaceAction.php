<?php

namespace App\Actions\Manage;

use App\Models\User;
use App\Models\Workspace;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查找系统默认工作区。
 */
class GetDefaultWorkspaceAction
{
    use AsAction;

    public function handle(User $user)
    {
        return Workspace::query()->where('owner_id', $user->id)->firstOrFail();
    }
}
