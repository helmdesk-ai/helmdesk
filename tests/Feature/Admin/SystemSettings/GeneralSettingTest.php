<?php

use App\Models\User;
use App\Services\SystemSetting\SystemBaseUrl;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = createSuperAdmin();
});

test('超级管理员可以查看通用设置页面', function () {
    $this->withoutExceptionHandling();

    actingAs($this->user, 'admin')
        ->get(route('admin.general.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/generalSetting/Index'));
});

test('超级管理员可以查看静态系统设置页面', function (string $routeName, string $component) {
    actingAs($this->user, 'admin')
        ->get(route($routeName))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['admin.mail.show', 'admin/systemSettings/MailSetting'],
]);

test('非超级管理员不能视图通用设置页面', function () {
    $user = User::factory()->create([
        'is_super_admin' => false,
    ]);

    actingAs($user, 'admin')
        ->get(route('admin.general.show'))
        ->assertForbidden();
});

test('未认证用户不能视图通用设置页面', function () {
    get(route('admin.general.show'))
        ->assertRedirect('/login');
});

test('通用设置默认品牌是HelmDesk', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->base_url)->toBe(GeneralSettings::DEFAULT_BASE_URL);
    expect($settings->name)->toBe('HelmDesk');
    expect($settings->copyright)->toBe('Copyright © 2026 HelmDesk');
    expect($settings->allow_registration)->toBeTrue();
});

test('超级管理员第一个访问填充默认库URL来自请求主机', function () {
    actingAs($this->user, 'admin')
        ->get('https://support.example.test/admin/general')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('generalSettings.base_url', 'https://support.example.test')
        );

    $settings = app(GeneralSettings::class);
    $settings->refresh();

    expect($settings->base_url)->toBe('https://support.example.test');
});

test('超级管理员访问不会覆盖自定义库URL', function () {
    $settings = app(GeneralSettings::class);
    $settings->base_url = 'https://custom.example.test';
    $settings->save();

    actingAs($this->user, 'admin')
        ->get('https://support.example.test/admin/general')
        ->assertOk();

    $settings->refresh();

    expect($settings->base_url)->toBe('https://custom.example.test');
});

test('超级管理员可以更新通用设置且包含全部字段', function () {
    config(['app.url' => 'https://old.example.test']);

    $response = actingAs($this->user, 'admin')
        ->put(route('admin.general.update'), [
            'base_url' => 'https://support.example.test',
            'name' => 'HelmDesk',
            'logo_id' => '01kepy83b9sxs4scf7q36mxa5z',
            'copyright' => '© 2026 HelmDesk',
            'icp_record' => '京ICP备12345678号',
            'allow_registration' => false,
        ]);

    $response->assertRedirect();

    // 验证设置已更新
    $settings = app(GeneralSettings::class);
    expect($settings->base_url)->toBe('https://support.example.test');
    expect($settings->name)->toBe('HelmDesk');
    expect($settings->copyright)->toBe('© 2026 HelmDesk');
    expect($settings->logo_id)->toBe('01kepy83b9sxs4scf7q36mxa5z');
    expect($settings->icp_record)->toBe('京ICP备12345678号');
    expect($settings->allow_registration)->toBeFalse();
    // 对外地址走 SystemBaseUrl 从 settings 现读，保存后即生效，无需回填 config('app.url')。
    expect(app(SystemBaseUrl::class)->value())->toBe('https://support.example.test');
});

test('超级管理员可以更新通用设置且只有必填字段', function () {
    actingAs($this->user, 'admin')
        ->put(route('admin.general.update'), [
            'base_url' => GeneralSettings::DEFAULT_BASE_URL,
            'name' => 'HelmDesk',
            'allow_registration' => true,
        ])
        ->assertRedirect();

    $settings = app(GeneralSettings::class);
    expect($settings->base_url)->toBe(GeneralSettings::DEFAULT_BASE_URL);
    expect($settings->name)->toBe('HelmDesk');
});

test('通用设置页面没有不再包含工作区AI运行时字段', function () {
    $page = file_get_contents(resource_path('js/pages/admin/generalSetting/Index.vue'));

    expect($page)->not->toContain("t('AI 全局最大并发')");
    expect($page)->not->toContain("t('AI 过载提示文案')");
});

test('通用设置更新校验无效载荷', function (array $payload, string $field) {
    actingAs($this->user, 'admin')
        ->put(route('admin.general.update'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'missing base url' => [['name' => '客服系统'], 'base_url'],
    'missing name' => [['base_url' => GeneralSettings::DEFAULT_BASE_URL], 'name'],
    'invalid base url' => [['base_url' => 'not-a-valid-url', 'name' => 'HelmDesk'], 'base_url'],
    'name too long' => [['base_url' => GeneralSettings::DEFAULT_BASE_URL, 'name' => str_repeat('a', 256)], 'name'],
    'logo too long' => [['base_url' => GeneralSettings::DEFAULT_BASE_URL, 'name' => 'HelmDesk', 'logo_id' => str_repeat('a', 501)], 'logo_id'],
]);

test('未认证用户不能更新通用设置', function () {
    put(route('admin.general.update'), [
        'base_url' => GeneralSettings::DEFAULT_BASE_URL,
        'name' => 'HelmDesk',
    ])
        ->assertRedirect('/login');
});
