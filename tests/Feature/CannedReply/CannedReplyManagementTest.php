<?php

use App\Enums\WorkspaceRole;
use App\Models\CannedReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->withoutVite();
    $this->owner = $this->createUserWithWorkspace();
});

test('Owner 可以查看快捷回复列表页面', function () {
    CannedReply::factory()
        ->for($this->workspace)
        ->create(['name' => '工作区共享 1']);

    CannedReply::factory()
        ->for($this->workspace)
        ->ownedBy($this->owner)
        ->create(['name' => '我的私有 1']);

    $this->actingAs($this->owner)
        ->get(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cannedReplies/Index')
            ->has('canned_reply_list', 2)
            ->has('available_tokens')
            ->where('can_manage_workspace_replies', true)
            ->etc()
        );
});

test('普通成员可以创建个人快捷回复', function () {
    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => WorkspaceRole::Operator->value]);

    $this->actingAs($member)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->post(route('workspace.canned-replies.store', ['slug' => $this->workspaceSlug()]), [
            'name' => '我的退款回复',
            'content' => '你好 {{contact.name}}，已收到。',
            'shortcut' => 'refund',
            'is_personal' => true,
        ])
        ->assertRedirect(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]));

    $reply = CannedReply::query()->where('name', '我的退款回复')->firstOrFail();
    expect((string) $reply->user_id)->toBe((string) $member->id);
    expect($reply->shortcut)->toBe('refund');
});

test('普通成员不能创建工作区共享快捷回复', function () {
    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => WorkspaceRole::Operator->value]);

    // BusinessException 在非 Inertia 请求中返回 422，符合既有处理约定。
    $this->actingAs($member)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->post(route('workspace.canned-replies.store', ['slug' => $this->workspaceSlug()]), [
            'name' => '团队共享',
            'content' => '通用回复',
            'is_personal' => false,
        ])
        ->assertStatus(422);

    expect(CannedReply::query()->where('name', '团队共享')->whereNull('user_id')->exists())->toBeFalse();
});

test('管理员可以创建工作区共享快捷回复', function () {
    $this->actingAs($this->owner)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->post(route('workspace.canned-replies.store', ['slug' => $this->workspaceSlug()]), [
            'name' => '团队共享 V2',
            'content' => '通用回复',
            'is_personal' => false,
        ])
        ->assertRedirect(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]));

    $reply = CannedReply::query()->where('name', '团队共享 V2')->firstOrFail();
    expect($reply->user_id)->toBeNull();
    expect((string) $reply->created_by_user_id)->toBe((string) $this->owner->id);
});

test('shortcut 在同一归属内不可重复', function () {
    CannedReply::factory()
        ->for($this->workspace)
        ->withShortcut('refund')
        ->create(['user_id' => null]);

    $this->actingAs($this->owner)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->post(route('workspace.canned-replies.store', ['slug' => $this->workspaceSlug()]), [
            'name' => '重复短码',
            'content' => 'test',
            'shortcut' => 'refund',
            'is_personal' => false,
        ])
        ->assertSessionHasErrors('shortcut');
});

test('普通成员只能编辑自己的个人模版', function () {
    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => WorkspaceRole::Operator->value]);

    $shared = CannedReply::factory()
        ->for($this->workspace)
        ->create(['name' => '共享']);

    $this->actingAs($member)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->put(
            route('workspace.canned-replies.update', [
                'slug' => $this->workspaceSlug(),
                'cannedReply' => $shared->id,
            ]),
            [
                'name' => '改名字',
                'content' => 'changed',
                'is_personal' => false,
            ]
        )
        ->assertStatus(422);

    expect($shared->fresh()->name)->toBe('共享');
});

test('成员可以编辑自己的个人模版', function () {
    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => WorkspaceRole::Operator->value]);

    $personal = CannedReply::factory()
        ->for($this->workspace)
        ->ownedBy($member)
        ->create(['name' => 'Old']);

    $this->actingAs($member)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->put(
            route('workspace.canned-replies.update', [
                'slug' => $this->workspaceSlug(),
                'cannedReply' => $personal->id,
            ]),
            [
                'name' => 'New name',
                'content' => 'new content',
                'is_personal' => true,
            ]
        )
        ->assertRedirect(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]));

    expect($personal->fresh()->name)->toBe('New name');
});

test('管理员可以把个人模版切换为工作区共享', function () {
    $personal = CannedReply::factory()
        ->for($this->workspace)
        ->ownedBy($this->owner)
        ->create(['name' => 'Mine']);

    $this->actingAs($this->owner)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->put(
            route('workspace.canned-replies.update', [
                'slug' => $this->workspaceSlug(),
                'cannedReply' => $personal->id,
            ]),
            [
                'name' => 'Mine',
                'content' => 'shared content',
                'is_personal' => false,
            ]
        )
        ->assertRedirect(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]));

    expect($personal->fresh()->user_id)->toBeNull();
});

test('管理员可以把工作区共享模版切换为自己个人', function () {
    $shared = CannedReply::factory()
        ->for($this->workspace)
        ->create(['name' => 'Team', 'user_id' => null]);

    $this->actingAs($this->owner)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->put(
            route('workspace.canned-replies.update', [
                'slug' => $this->workspaceSlug(),
                'cannedReply' => $shared->id,
            ]),
            [
                'name' => 'Team',
                'content' => 'personal copy',
                'is_personal' => true,
            ]
        )
        ->assertRedirect(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]));

    expect((string) $shared->fresh()->user_id)->toBe((string) $this->owner->id);
});

test('普通成员不能把自己的个人模版切换为工作区共享', function () {
    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => WorkspaceRole::Operator->value]);

    $personal = CannedReply::factory()
        ->for($this->workspace)
        ->ownedBy($member)
        ->create(['name' => 'Mine']);

    $this->actingAs($member)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->put(
            route('workspace.canned-replies.update', [
                'slug' => $this->workspaceSlug(),
                'cannedReply' => $personal->id,
            ]),
            [
                'name' => 'Mine',
                'content' => 'changed',
                'is_personal' => false,
            ]
        )
        ->assertStatus(422);

    expect($personal->fresh()->user_id)->not->toBeNull();
});

test('切换归属后会按新范围检查短码冲突', function () {
    CannedReply::factory()
        ->for($this->workspace)
        ->withShortcut('refund')
        ->create(['user_id' => null]);

    $personal = CannedReply::factory()
        ->for($this->workspace)
        ->ownedBy($this->owner)
        ->withShortcut('refund')
        ->create(['name' => 'Mine']);

    $this->actingAs($this->owner)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->put(
            route('workspace.canned-replies.update', [
                'slug' => $this->workspaceSlug(),
                'cannedReply' => $personal->id,
            ]),
            [
                'name' => 'Mine',
                'content' => 'shared promote',
                'shortcut' => 'refund',
                'is_personal' => false,
            ]
        )
        ->assertSessionHasErrors('shortcut');

    expect($personal->fresh()->user_id)->not->toBeNull();
});

test('删除会软删除模版', function () {
    $reply = CannedReply::factory()
        ->for($this->workspace)
        ->ownedBy($this->owner)
        ->create();

    $this->actingAs($this->owner)
        ->from(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]))
        ->delete(route('workspace.canned-replies.destroy', [
            'slug' => $this->workspaceSlug(),
            'cannedReply' => $reply->id,
        ]))
        ->assertRedirect(route('workspace.canned-replies.index', ['slug' => $this->workspaceSlug()]));

    expect(CannedReply::query()->find($reply->id))->toBeNull();
    expect(CannedReply::withTrashed()->find($reply->id)->trashed())->toBeTrue();
});

test('删除用户时会删除其个人模版但保留工作区共享模版', function () {
    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => WorkspaceRole::Operator->value]);

    $personal = CannedReply::factory()
        ->for($this->workspace)
        ->ownedBy($member)
        ->create();

    $shared = CannedReply::factory()
        ->for($this->workspace)
        ->create(['user_id' => null]);

    $member->forceDelete();

    expect(CannedReply::query()->find($personal->id))->toBeNull();
    expect(CannedReply::query()->find($shared->id))->not->toBeNull();
});

test('非工作区成员访问设置页会被拦截', function () {
    $stranger = User::factory()->create();

    // IdentifyWorkspace 中间件会先一步把非成员挡掉，返回 404，避免泄露工作区是否存在。
    $this->actingAs($stranger)
        ->get(route('workspace.canned-replies.index', [
            'slug' => $this->workspaceSlug(),
        ]))
        ->assertNotFound();
});

test('更新跨工作区模版返回 404', function () {
    $reply = CannedReply::factory()
        ->for($this->workspace)
        ->create();

    $secondOwner = $this->createUserWithWorkspace();
    $secondWorkspaceSlug = $this->workspaceSlug();

    $this->actingAs($secondOwner)
        ->put(route('workspace.canned-replies.update', [
            'slug' => $secondWorkspaceSlug,
            'cannedReply' => $reply->id,
        ]), [
            'name' => 'Cross workspace',
            'content' => 'changed',
            'is_personal' => false,
        ])
        ->assertNotFound();
});
