<?php

use App\Models\Attachment;
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

function bindStorageProfileCorsRules(array $rules): void
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

function validStorageProfileCorsRules(): array
{
    return [[
        'AllowedOrigins' => ['*'],
        'AllowedMethods' => ['POST', 'PUT'],
        'AllowedHeaders' => ['*'],
        'ExposeHeaders' => ['ETag'],
    ]];
}

function fakeStorageProfileDisk(): FilesystemAdapter
{
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturnTrue();
    $disk->shouldReceive('size')->once()->andReturn(2);
    $disk->shouldReceive('delete')->once()->andReturnTrue();

    return $disk;
}

test('已认证用户可以查看创建存储配置档页面', function () {
    $this->actingAs($this->user, 'admin')
        ->get(route('admin.storage.profiles.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/storageSetting/Create')
            ->has('providers')
        );
});

test('未认证用户不能视图创建存储配置档页面', function () {
    $this->get(route('admin.storage.profiles.create'))
        ->assertRedirect('/login');
});

test('已认证用户可以查看编辑存储配置档页面', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
    ]);

    $this->actingAs($this->user, 'admin')
        ->get(route('admin.storage.profiles.edit', ['profile' => $profile->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/storageSetting/Edit')
            ->has('providers')
            ->has('profile')
            ->where('profile.id', (string) $profile->id)
            ->where('profile.name', 'p1')
        );
});

test('编辑存储配置档页面显示全部创建字段并锁定连接参数', function () {
    $page = file_get_contents(resource_path('js/pages/admin/storageSetting/Edit.vue'));

    expect($page)->not->toContain("t('基础信息')");
    expect($page)->not->toContain("t('区域与 Endpoint')");
    expect($page)->not->toContain("t('访问凭据')");
    expect($page)->not->toContain("t('可选项')");
    expect($page)->not->toContain('Separator');
    expect($page)->not->toContain("t('已创建后不可修改')");
    expect($page)->not->toContain("t('凭据留空表示保持不变；只在需要轮换时填写。')");
    expect($page)->toContain("t('配置名称')");
    expect($page)->toContain("t('存储提供商')");
    expect($page)->toContain("t('区域 (Region)')");
    expect($page)->toContain("t('Endpoint 地址')");
    expect($page)->toContain("t('Bucket 名称')");
    expect($page)->toContain("t('Access Key / Access Key ID')");
    expect($page)->toContain("t('Secret Key / Access Key Secret')");
    expect($page)->toContain("t('自定义域名 (可选)')");
    expect($page)->toContain(':model-value="profileProviderLabel"');
    expect($page)->toContain(':model-value="profileRegionLabel"');
    expect($page)->toContain(':model-value="nullToEmpty(props.profile.endpoint)"');
    expect($page)->toContain(':model-value="nullToEmpty(props.profile.bucket)"');
    expect($page)->toContain('storageProfile.check.url(props.profile.id)');
    expect($page)->toContain('CheckStorageSettingAction.url()');
    expect($page)->toContain('readonly');
});

test('创建存储配置档页面使用普通表单布局而非分组区块', function () {
    $page = file_get_contents(resource_path('js/pages/admin/storageSetting/Create.vue'));

    expect($page)->not->toContain("t('基础信息')");
    expect($page)->not->toContain("t('区域与 Endpoint')");
    expect($page)->not->toContain("t('访问凭据')");
    expect($page)->not->toContain("t('可选项')");
    expect($page)->not->toContain('Separator');
});

test('未认证用户不能视图编辑存储配置档页面', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
    ]);

    $this->get(route('admin.storage.profiles.edit', ['profile' => $profile->id]))
        ->assertRedirect('/login');
});

test('已认证用户可以创建存储配置档且不运行连接检查', function () {
    Storage::shouldReceive('build')->never();

    $this->actingAs($this->user, 'admin')
        ->post(route('admin.storage.profiles.store'), [
            'name' => 'tencent-prod',
            'provider' => 'tencent',
            'region' => 'ap-guangzhou',
            'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
            'bucket' => 'bucket',
            'key' => 'key',
            'secret' => 'secret',
            'url' => 'https://cdn.example.com',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile = StorageProfile::query()->firstOrFail();
    expect($profile->name)->toBe('tencent-prod');
    expect($profile->provider->value)->toBe('tencent');
    expect($profile->region)->toBe('ap-guangzhou');
    expect($profile->endpoint)->toBe('https://cos.ap-guangzhou.myqcloud.com');
    expect($profile->bucket)->toBe('bucket');
    expect($profile->access_key)->toBe('key');
    expect($profile->secret_key)->toBe('secret');
    expect($profile->public_url)->toBe('https://cdn.example.com');
});

test('创建存储配置档失败当提供商是无效枚举值时', function () {
    Storage::shouldReceive('build')->never();

    $this->actingAs($this->user, 'admin')
        ->post(route('admin.storage.profiles.store'), [
            'name' => 'bad-provider',
            'provider' => 'not-a-provider',
            'region' => 'ap-guangzhou',
            'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
            'bucket' => 'bucket',
            'key' => 'key',
            'secret' => 'secret',
            'url' => 'https://cdn.example.com',
        ])
        ->assertSessionHasErrors('provider');
});

test('创建存储配置档成功即使当存储凭证无法连接时', function () {
    Storage::shouldReceive('build')->never();

    $this->actingAs($this->user, 'admin')
        ->post(route('admin.storage.profiles.store'), [
            'name' => 'tencent-prod',
            'provider' => 'tencent',
            'region' => 'ap-guangzhou',
            'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
            'bucket' => 'bucket',
            'key' => 'key',
            'secret' => 'secret',
            'url' => 'https://cdn.example.com',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(StorageProfile::query()->count())->toBe(1);
});

test('已认证用户只能更新可编辑配置档字段且不改变凭证', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
        'public_url' => null,
    ]);

    Storage::shouldReceive('build')->never();

    $this->actingAs($this->user, 'admin')
        ->put(route('admin.storage.profiles.update', ['profile' => $profile->id]), [
            'name' => 'p1-new',
            'region' => 'ap-shanghai',
            'endpoint' => 'https://cos.ap-shanghai.myqcloud.com',
            'bucket' => 'bucket-new',
            'url' => 'https://cdn.example.com',
            'key' => '',
            'secret' => '',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile->refresh();
    expect($profile->name)->toBe('p1-new');
    expect($profile->region)->toBe('ap-guangzhou');
    expect($profile->endpoint)->toBe('https://cos.ap-guangzhou.myqcloud.com');
    expect($profile->bucket)->toBe('bucket');
    expect($profile->public_url)->toBe('https://cdn.example.com');
    expect($profile->access_key)->toBe('key');
    expect($profile->secret_key)->toBe('secret');
});

test('更新凭证需要键和密钥', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
    ]);

    Storage::shouldReceive('build')->never();

    $this->actingAs($this->user, 'admin')
        ->put(route('admin.storage.profiles.update', ['profile' => $profile->id]), [
            'name' => 'p1',
            'url' => '',
            'key' => 'new-key',
            'secret' => '',
        ])
        ->assertSessionHasErrors('secret');
});

test('已认证用户可以更新凭证且不运行连接检查', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
    ]);

    Storage::shouldReceive('build')->never();

    $this->actingAs($this->user, 'admin')
        ->put(route('admin.storage.profiles.update', ['profile' => $profile->id]), [
            'name' => 'p1',
            'url' => '',
            'key' => 'new-key',
            'secret' => 'new-secret',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $profile->refresh();
    expect($profile->access_key)->toBe('new-key');
    expect($profile->secret_key)->toBe('new-secret');
});

test('已认证用户可以检查存储配置档连接', function () {
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
        ->andReturn(fakeStorageProfileDisk());
    bindStorageProfileCorsRules(validStorageProfileCorsRules());

    $this->actingAs($this->user, 'admin')
        ->put(route('admin.storage.profiles.check', ['profile' => $profile->id]))
        ->assertRedirect();
});

test('存储配置档连接检查失败当CORS不支持浏览器直传时', function () {
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
        ->andReturn(fakeStorageProfileDisk());
    bindStorageProfileCorsRules([[
        'AllowedOrigins' => ['https://other.example.com'],
        'AllowedMethods' => ['POST'],
        'AllowedHeaders' => ['*'],
        'ExposeHeaders' => [],
    ]]);

    $this->actingAs($this->user, 'admin')
        ->put(route('admin.storage.profiles.check', ['profile' => $profile->id]), [], [
            'Origin' => 'https://app.example.com',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('endpoint');
});

test('不能删除当前已选择配置档', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
    ]);

    /** @var StorageSettings $settings */
    $settings = app(StorageSettings::class);
    $settings->enabled = true;
    $settings->current_profile_id = (string) $profile->id;
    $settings->save();

    $this->actingAs($this->user, 'admin')
        ->delete(route('admin.storage.profiles.destroy', ['profile' => $profile->id]))
        ->assertSessionHasErrors('profile');
});

test('可以删除之前已选择配置档当存储禁用时', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
    ]);

    /** @var StorageSettings $settings */
    $settings = app(StorageSettings::class);
    $settings->enabled = false;
    $settings->current_profile_id = (string) $profile->id;
    $settings->save();

    $this->actingAs($this->user, 'admin')
        ->delete(route('admin.storage.profiles.destroy', ['profile' => $profile->id]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(StorageProfile::query()->whereKey($profile->id)->exists())->toBeFalse();
});

test('不能删除配置档是被引用按附件', function () {
    $profile = StorageProfile::query()->create([
        'name' => 'p1',
        'provider' => 'tencent',
        'region' => 'ap-guangzhou',
        'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'bucket' => 'bucket',
        'access_key' => 'key',
        'secret_key' => 'secret',
    ]);

    Attachment::factory()->create([
        'disk' => 's3',
        'storage_profile_id' => $profile->id,
        'bucket' => 'bucket',
        'object_key' => 'uploads/a.png',
        'original_name' => 'a.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 123,
        'attachable_id' => null,
        'attachable_type' => null,
    ]);

    $this->actingAs($this->user, 'admin')
        ->delete(route('admin.storage.profiles.destroy', ['profile' => $profile->id]))
        ->assertSessionHasErrors('profile');
});
