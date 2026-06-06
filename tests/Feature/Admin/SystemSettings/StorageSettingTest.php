<?php

use App\Models\StorageProfile;
use App\Services\Storage\S3ClientFactory;
use App\Settings\StorageSettings;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = createSuperAdmin();
});

function bindStorageSettingCorsRules(array $rules): void
{
    $client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => 'http://minio.test',
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'key', 'secret' => 'secret'],
        'handler' => new MockHandler([
            new Result(['CORSRules' => $rules]),
        ]),
    ]);

    app()->instance(S3ClientFactory::class, new class($client) extends S3ClientFactory
    {
        public function __construct(private readonly S3Client $client) {}

        public function make(StorageProfile $profile): S3Client
        {
            return $this->client;
        }
    });
}

function validStorageSettingCorsRules(): array
{
    return [[
        'AllowedOrigins' => ['*'],
        'AllowedMethods' => ['POST', 'PUT'],
        'AllowedHeaders' => ['*'],
        'ExposeHeaders' => ['ETag'],
    ]];
}

function fakeStorageSettingDisk(bool $failOnPut = false): FilesystemAdapter
{
    $disk = Mockery::mock(FilesystemAdapter::class);

    if ($failOnPut) {
        $disk->shouldReceive('put')->once()->andThrow(new RuntimeException('connection failed'));
        $disk->shouldReceive('size')->never();
        $disk->shouldReceive('delete')->never();

        return $disk;
    }

    $disk->shouldReceive('put')->once()->andReturnTrue();
    $disk->shouldReceive('size')->once()->andReturn(2);
    $disk->shouldReceive('delete')->once()->andReturnTrue();

    return $disk;
}

test('未认证用户不能视图存储设置页面', function () {
    $this->get(route('admin.storage.show'))
        ->assertRedirect('/login');
});

test('已认证用户可以查看存储设置页面(新结构)', function () {
    /** @var StorageSettings $settings */
    $settings = app(StorageSettings::class);
    $settings->enabled = true;
    $settings->current_profile_id = null;
    $settings->save();

    $this->actingAs($this->user)
        ->get(route('admin.storage.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/storageSetting/Index')
            ->has('settings')
            ->has('profiles')
            ->has('providers')
            ->where('settings.enabled', true)
            ->where('settings.current_profile_id', null)
        );
});

test('存储设置列表使用操作按钮切换并拆分列且不显示CORS指南', function () {
    $page = file_get_contents(resource_path('js/pages/admin/storageSetting/Index.vue'));

    expect($page)->toContain("t('存储供应商')");
    expect($page)->toContain("t('区域')");
    expect($page)->toContain("t('Endpoint 地址')");
    expect($page)->toContain("t('Bucket')");
    expect($page)->toContain("t('本地磁盘')");
    expect($page)->toContain("t('当前服务器')");
    expect($page)->toContain("t('应用本地文件系统')");
    expect($page)->toContain("t('本地 private 目录')");
    expect($page)->not->toContain("t('CORS 指引')");
    expect($page)->not->toContain('Allowed Methods');
    expect($page)->not->toContain('Expose Headers');
    expect($page)->not->toContain('>Bucket<');
    expect($page)->not->toContain("t('在用')");
    expect($page)->not->toContain("t('Access Key')");
    expect($page)->not->toContain("t('文件保存到服务器本地的 public 磁盘目录。')");
    expect($page)->not->toContain('cursor-pointer');
    expect($page)->toContain("t('切换')");
});

test('已认证用户可以切回本地存储和清除current_profile_id', function () {
    /** @var StorageSettings $settings */
    $settings = app(StorageSettings::class);
    $settings->enabled = true;
    $settings->current_profile_id = '01testprofile';
    $settings->save();

    $this->actingAs($this->user)
        ->put(route('admin.storage.update'), [
            'enabled' => false,
            'current_profile_id' => null,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings->refresh();
    expect($settings->enabled)->toBeFalse();
    expect($settings->current_profile_id)->toBeNull();
});

test('启用存储需要current_profile_id', function () {
    $this->actingAs($this->user)
        ->put(route('admin.storage.update'), [
            'enabled' => true,
            'current_profile_id' => '',
        ])
        ->assertSessionHasErrors('current_profile_id');
});

test('启用存储失败当所选配置档不存在时', function () {
    $this->actingAs($this->user)
        ->put(route('admin.storage.update'), [
            'enabled' => true,
            'current_profile_id' => '01doesnotexist',
        ])
        ->assertSessionHasErrors('current_profile_id');
});

test('启用存储失败当配置档缺少凭证时', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => null,
        'secret_key' => null,
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.storage.update'), [
            'enabled' => true,
            'current_profile_id' => $profile->id,
        ])
        ->assertSessionHasErrors('current_profile_id');
});

test('已认证用户可以启用存储和保存设置当配置档连接检查通过', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
        'public_url' => 'https://cdn.example.com',
    ]);

    Storage::shouldReceive('build')
        ->once()
        ->andReturn(fakeStorageSettingDisk());
    bindStorageSettingCorsRules(validStorageSettingCorsRules());

    $this->actingAs($this->user)
        ->put(route('admin.storage.update'), [
            'enabled' => true,
            'current_profile_id' => $profile->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    /** @var StorageSettings $settings */
    $settings = app(StorageSettings::class)->refresh();
    expect($settings->enabled)->toBeTrue();
    expect($settings->current_profile_id)->toBe((string) $profile->id);
});

test('存储检查按钮校验存储桶CORS用于浏览器直传上传', function () {
    Storage::shouldReceive('build')
        ->once()
        ->andReturn(fakeStorageSettingDisk());
    bindStorageSettingCorsRules([[
        'AllowedOrigins' => ['https://other.example.com'],
        'AllowedMethods' => ['POST'],
        'AllowedHeaders' => ['*'],
        'ExposeHeaders' => [],
    ]]);

    $this->actingAs($this->user)
        ->put(route('admin.storage.check'), [
            'provider' => 'tencent',
            'region' => 'ap-guangzhou',
            'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
            'bucket' => 'bucket',
            'key' => 'key',
            'secret' => 'secret',
            'url' => null,
        ], [
            'Origin' => 'https://app.example.com',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('endpoint');
});

test('启用存储失败当存储桶CORS不允许浏览器直传时', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
    ]);

    Storage::shouldReceive('build')
        ->once()
        ->andReturn(fakeStorageSettingDisk());
    bindStorageSettingCorsRules([[
        'AllowedOrigins' => ['https://other.example.com'],
        'AllowedMethods' => ['POST'],
        'AllowedHeaders' => ['*'],
        'ExposeHeaders' => [],
    ]]);

    $this->actingAs($this->user)
        ->put(route('admin.storage.update'), [
            'enabled' => true,
            'current_profile_id' => $profile->id,
        ], [
            'Origin' => 'https://app.example.com',
        ])
        ->assertSessionHasErrors('endpoint');

    /** @var StorageSettings $settings */
    $settings = app(StorageSettings::class)->refresh();
    expect($settings->enabled)->toBeFalse();
    expect($settings->current_profile_id)->toBeNull();
});

test('更新失败并返回字段错误当配置档连接检查抛出异常时', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
    ]);

    Storage::shouldReceive('build')
        ->once()
        ->andReturn(fakeStorageSettingDisk(failOnPut: true));

    $this->actingAs($this->user)
        ->put(route('admin.storage.update'), [
            'enabled' => true,
            'current_profile_id' => $profile->id,
        ])
        ->assertSessionHasErrors('current_profile_id');
});
