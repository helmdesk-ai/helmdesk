<?php

use App\Models\CannedReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->withoutVite();
    $this->owner = $this->createUserWithSystem();
});

test('超级管理员可以查看快捷回复列表页面', function () {
    CannedReply::factory()
        ->create(['name' => '系统共享 1']);

    CannedReply::factory()
        ->ownedBy($this->owner)
        ->create(['name' => '我的私有 1']);

    $this->actingAs($this->owner)
        ->get(route('admin.canned-replies.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cannedReplies/Index')
            ->has('canned_reply_list', 2)
            ->has('available_tokens')
            ->where('can_manage_system_replies', true)
            ->etc()
        );
});

test('超级管理员可以创建个人快捷回复', function () {
    $this->actingAs($this->owner)
        ->from(route('admin.canned-replies.index'))
        ->post(route('admin.canned-replies.store'), [
            'name' => '我的退款回复',
            'content' => '你好 {{contact.name}}，已收到。',
            'shortcut' => 'refund',
            'is_personal' => true,
        ])
        ->assertRedirect(route('admin.canned-replies.index'));

    $reply = CannedReply::query()->where('name', '我的退款回复')->firstOrFail();
    expect((string) $reply->user_id)->toBe((string) $this->owner->id);
    expect($reply->shortcut)->toBe('refund');
});

test('超级管理员可以创建共享快捷回复', function () {
    $this->actingAs($this->owner)
        ->from(route('admin.canned-replies.index'))
        ->post(route('admin.canned-replies.store'), [
            'name' => '团队共享 V2',
            'content' => '通用回复',
            'is_personal' => false,
        ])
        ->assertRedirect(route('admin.canned-replies.index'));

    $reply = CannedReply::query()->where('name', '团队共享 V2')->firstOrFail();
    expect($reply->user_id)->toBeNull();
    expect((string) $reply->created_by_user_id)->toBe((string) $this->owner->id);
});

test('shortcut 在同一归属内不可重复', function () {
    CannedReply::factory()
        ->withShortcut('refund')
        ->create(['user_id' => null]);

    $this->actingAs($this->owner)
        ->from(route('admin.canned-replies.index'))
        ->post(route('admin.canned-replies.store'), [
            'name' => '重复短码',
            'content' => 'test',
            'shortcut' => 'refund',
            'is_personal' => false,
        ])
        ->assertSessionHasErrors('shortcut');
});

test('超级管理员可以编辑自己的个人模版', function () {
    $personal = CannedReply::factory()
        ->ownedBy($this->owner)
        ->create(['name' => 'Old']);

    $this->actingAs($this->owner)
        ->from(route('admin.canned-replies.index'))
        ->put(
            route('admin.canned-replies.update', ['cannedReply' => $personal->id,
            ]),
            [
                'name' => 'New name',
                'content' => 'new content',
                'is_personal' => true,
            ]
        )
        ->assertRedirect(route('admin.canned-replies.index'));

    expect($personal->fresh()->name)->toBe('New name');
});

test('超级管理员可以把个人模版切换为共享', function () {
    $personal = CannedReply::factory()
        ->ownedBy($this->owner)
        ->create(['name' => 'Mine']);

    $this->actingAs($this->owner)
        ->from(route('admin.canned-replies.index'))
        ->put(
            route('admin.canned-replies.update', ['cannedReply' => $personal->id,
            ]),
            [
                'name' => 'Mine',
                'content' => 'shared content',
                'is_personal' => false,
            ]
        )
        ->assertRedirect(route('admin.canned-replies.index'));

    expect($personal->fresh()->user_id)->toBeNull();
});

test('超级管理员可以把共享模版切换为自己个人', function () {
    $shared = CannedReply::factory()
        ->create(['name' => 'Team', 'user_id' => null]);

    $this->actingAs($this->owner)
        ->from(route('admin.canned-replies.index'))
        ->put(
            route('admin.canned-replies.update', ['cannedReply' => $shared->id,
            ]),
            [
                'name' => 'Team',
                'content' => 'personal copy',
                'is_personal' => true,
            ]
        )
        ->assertRedirect(route('admin.canned-replies.index'));

    expect((string) $shared->fresh()->user_id)->toBe((string) $this->owner->id);
});

test('切换归属后会按新范围检查短码冲突', function () {
    CannedReply::factory()
        ->withShortcut('refund')
        ->create(['user_id' => null]);

    $personal = CannedReply::factory()
        ->ownedBy($this->owner)
        ->withShortcut('refund')
        ->create(['name' => 'Mine']);

    $this->actingAs($this->owner)
        ->from(route('admin.canned-replies.index'))
        ->put(
            route('admin.canned-replies.update', ['cannedReply' => $personal->id,
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
        ->ownedBy($this->owner)
        ->create();

    $this->actingAs($this->owner)
        ->from(route('admin.canned-replies.index'))
        ->delete(route('admin.canned-replies.destroy', ['cannedReply' => $reply->id,
        ]))
        ->assertRedirect(route('admin.canned-replies.index'));

    expect(CannedReply::query()->find($reply->id))->toBeNull();
    expect(CannedReply::withTrashed()->find($reply->id)->trashed())->toBeTrue();
});

test('删除用户时会删除其个人模版但保留共享模版', function () {
    $member = User::factory()->create();

    $personal = CannedReply::factory()
        ->ownedBy($member)
        ->create();

    $shared = CannedReply::factory()
        ->create(['user_id' => null]);

    $member->forceDelete();

    expect(CannedReply::query()->find($personal->id))->toBeNull();
    expect(CannedReply::query()->find($shared->id))->not->toBeNull();
});
