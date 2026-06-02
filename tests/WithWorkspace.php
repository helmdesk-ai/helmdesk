<?php

namespace Tests;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

trait WithWorkspace
{
    public ?Workspace $workspace = null;

    /**
     * 旧工作区后台测试默认使用总管理后台 guard。
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        return $this->be($user, $guard ?? 'admin');
    }

    /**
     * @param  array<string, mixed>  $userAttributes
     * @param  array<string, mixed>  $workspaceAttributes
     */
    protected function createUserWithWorkspace(array $userAttributes = [], array $workspaceAttributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_super_admin' => true,
        ], $userAttributes));

        $this->workspace = Workspace::factory()->create(array_merge([
            'owner_id' => $user->id,
        ], $workspaceAttributes));

        return $user;
    }

    /**
     * 将用户标记为可访问单租户后台。
     */
    protected function attachWorkspace(User $user, ?Workspace $workspace = null, string $role = 'owner'): Workspace
    {
        $this->workspace = $workspace ?? Workspace::factory()->create();

        if ($role === 'owner') {
            $user->forceFill(['is_super_admin' => true])->save();
        }

        return $this->workspace;
    }

    /**
     * 返回兼容旧测试路由调用的后台 slug。
     */
    protected function workspaceSlug(): string
    {
        return 'admin';
    }
}
