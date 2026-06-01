<?php

namespace Tests;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

trait WithWorkspace
{
    public ?Workspace $workspace = null;

    /**
     * @param  array<string, mixed>  $userAttributes
     * @param  array<string, mixed>  $workspaceAttributes
     */
    protected function createUserWithWorkspace(array $userAttributes = [], array $workspaceAttributes = []): User
    {
        $user = User::factory()->create($userAttributes);
        $this->workspace = Workspace::factory()->create(array_merge([
            'owner_id' => $user->id,
        ], $workspaceAttributes));
        $user->workspaces()->attach($this->workspace, ['role' => WorkspaceRole::Owner->value]);

        return $user;
    }

    /**
     * @param  value-of<WorkspaceRole>  $role
     */
    protected function attachWorkspace(User $user, ?Workspace $workspace = null, string $role = 'owner'): Workspace
    {
        $this->workspace = $workspace ?? Workspace::factory()->create();
        $user->workspaces()->attach($this->workspace, ['role' => $role]);

        return $this->workspace;
    }

    /**
     * Get the workspace slug for route generation
     */
    protected function workspaceSlug(): string
    {
        return $this->workspace?->slug ?? 'default';
    }
}
