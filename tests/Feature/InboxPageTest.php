<?php

use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('访客用户会被重定向到登录页面', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('inbox'))->assertRedirect(route('login'));
});

test('已认证用户进入仪表盘和可以打开收件箱', function () {
    [$systemContext, $user] = createSystemWithOwner();

    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard'));

    $this->get(route('admin.home'))
        ->assertRedirect(route('admin.dashboard'));

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('systemUserContext')
            ->where('systemUserContext.system_slug', 'admin')
            ->missing('currentSystem')
        );

    $this->get(route('inbox'))
        ->assertRedirect(route('admin.inbox.show'));

    $this->get(route('admin.inbox.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')
            ->has('enabled_web_channels', 0)
            ->has('reply_assistant_mode_options', 2)
            ->has('reply_polish_tone_options', 4)
            ->has('systemUserContext')
            ->where('systemUserContext.system_slug', 'admin')
            ->missing('currentSystem')
        );
});

test('访问仪表盘刷新用户最后活跃时间戳', function () {
    [$systemContext, $user] = createSystemWithOwner();
    $previousLastActiveAt = now()->subDay();
    $user->forceFill(['last_active_at' => $previousLastActiveAt])->save();

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk();

    $updatedLastActiveAt = $user->fresh()->last_active_at;

    expect($updatedLastActiveAt)->not->toBeNull()
        ->and($updatedLastActiveAt->isAfter($previousLastActiveAt))->toBeTrue();
});

test('收件箱只显示非已删除网页频道', function () {
    [$systemContext, $user] = createSystemWithOwner();

    $enabledChannel = Channel::factory()->create([
        'name' => '官网主站',
    ]);

    $secondChannel = Channel::factory()->create([
        'name' => '帮助中心',
    ]);

    Channel::factory()->create([
        'name' => '其他系统网站',
    ]);

    $deletedChannel = Channel::factory()->create([
        'name' => '已删除网站',
    ]);
    $deletedChannel->delete();

    $this->actingAs($user)
        ->get(route('admin.inbox.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')
            ->has('enabled_web_channels', 3)
            ->where('enabled_web_channels.0.type_label', '网站')
            ->etc()
        );
});
