<?php

namespace Tests;

use App\Models\SystemContext;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

trait WithSystemContext
{
    public ?SystemContext $systemContext = null;

    /**
     * 系统后台测试默认使用统一登录 guard。
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        return $this->be($user, $guard ?? 'web');
    }

    /**
     * @param  array<string, mixed>  $userAttributes
     * @param  array<string, mixed>  $systemAttributes
     */
    protected function createUserWithSystem(array $userAttributes = [], array $systemAttributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_super_admin' => true,
        ], $userAttributes));

        $this->systemContext = SystemContext::factory()->create(array_merge([
            'owner_id' => $user->id,
        ], $systemAttributes));

        return $user;
    }

    /**
     * 将用户标记为可访问单租户后台。
     */
    protected function attachSystem(User $user, ?SystemContext $systemContext = null): SystemContext
    {
        $this->systemContext = $systemContext ?? SystemContext::factory()->create();
        $user->forceFill(['is_super_admin' => true])->save();

        return $this->systemContext;
    }
}
