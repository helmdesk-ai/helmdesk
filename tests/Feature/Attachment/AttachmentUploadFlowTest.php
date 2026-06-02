<?php

use App\Actions\Attachment\ValidateAttachmentUploadAction;
use App\Enums\AttachmentStatus;
use App\Enums\AttachmentUploadMode;
use App\Enums\AttachmentUploadStatus;
use App\Models\Attachment;
use App\Models\AttachmentUpload;
use App\Models\Channel;
use App\Models\StorageProfile;
use App\Models\User;
use App\Services\Storage\AttachmentUrlResolver;
use App\Services\Storage\S3ClientFactory;
use App\Settings\StorageSettings;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace();

    /** @var StorageSettings $settings */
    $settings = app(StorageSettings::class);
    $settings->enabled = false;
    $settings->current_profile_id = null;
    $settings->save();
});

afterEach(function () {
    if ($this->workspace) {
        Storage::disk('local')->deleteDirectory('workspaces/'.$this->workspace->id);
    }
});

test('本地代理上传会完成为私有附件且下载需要工作区访问权限', function () {
    $contents = 'hello attachment';
    $file = UploadedFile::fake()->createWithContent('note.txt', $contents);

    $createResponse = $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads', [
            'purpose' => 'conversation_file',
            'file_name' => 'note.txt',
            'mime_type' => 'text/plain',
            'byte_size' => $file->getSize(),
            'context' => [],
        ])
        ->assertOk()
        ->assertJsonPath('upload.mode', 'proxy')
        ->assertJsonPath('attachment.status', 'pending');

    $attachmentId = $createResponse->json('attachment.id');
    $uploadId = $createResponse->json('upload.id');

    $this->actingAs($this->user)
        ->post('/api/attachments/uploads/'.$uploadId.'/proxy', ['file' => $file])
        ->assertOk()
        ->assertJsonPath('upload.status', 'uploading');

    $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads/'.$uploadId.'/complete')
        ->assertOk()
        ->assertJsonPath('attachment.status', 'uploaded')
        ->assertJsonPath('attachment.id', $attachmentId);

    $attachment = Attachment::query()->findOrFail($attachmentId);

    expect($attachment->status)->toBe(AttachmentStatus::Uploaded)
        ->and($attachment->visibility->value)->toBe('private');

    Storage::disk('local')->assertExists($attachment->object_key);

    $urlResolver = app(AttachmentUrlResolver::class);
    $url = $urlResolver->url($attachment);
    expect($url)->toStartWith('/attachments/dl?')
        ->and($url)->toContain('key=')
        ->and($url)->toContain('mime=')
        ->and($url)->toContain('name=')
        ->and($url)->toContain('expires=')
        ->and($url)->toContain('sig=');
});

test('自包含下载URL签名随参数变化', function () {
    $profile = StorageProfile::factory()->local()->create();
    $attachment = Attachment::factory()->create([
        'storage_profile_id' => $profile->id,
        'disk' => 'local',
        'object_key' => 'workspaces/'.$this->workspace->id.'/files/private.txt',
        'original_name' => 'private.txt',
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'byte_size' => 7,
        'visibility' => 'private',
        'status' => AttachmentStatus::Uploaded,
    ]);

    $urlResolver = app(AttachmentUrlResolver::class);
    $url = $urlResolver->url($attachment);

    // 提取签名
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);
    expect($params['sig'] ?? '')->not->toBeEmpty();

    // 篡改 key 后签名不同
    $tamperedParams = $params;
    $tamperedParams['key'] = 'tampered/path';
    $tampered = '/attachments/dl?'.http_build_query($tamperedParams);
    expect($tampered)->not->toBe($url);
});

test('上传意图会强制校验用途、大小和MIME策略', function () {
    $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads', [
            'purpose' => 'avatar',
            'file_name' => 'avatar.png',
            'mime_type' => 'image/png',
            'byte_size' => (2 * 1024 * 1024) + 1,
            'context' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('byte_size');

    $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads', [
            'purpose' => 'conversation_file',
            'file_name' => 'page.html',
            'mime_type' => 'text/html',
            'byte_size' => 1024,
            'context' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mime_type');
});

test('已认证上传路由需要已登录在用户', function () {
    $this->postJson('/api/attachments/uploads', [
        'purpose' => 'conversation_file',
        'file_name' => 'note.txt',
        'mime_type' => 'text/plain',
        'byte_size' => 5,
        'context' => [],
    ])->assertUnauthorized();
});

test('访客上传路由使用访客会话Cookie', function () {
    $token = str_repeat('a', 32);
    $channel = Channel::factory()->create([
    ]);
    $file = UploadedFile::fake()->image('photo.png', 64, 64);

    $createResponse = $this->withCredentials()
        ->withUnencryptedCookie('helmdesk_visitor_'.$channel->code, $token)
        ->postJson('/api/visitor/attachments/uploads', [
            'purpose' => 'conversation_image',
            'file_name' => 'photo.png',
            'mime_type' => 'image/png',
            'byte_size' => $file->getSize(),
            'context' => ['channel_code' => $channel->code],
        ])
        ->assertOk()
        ->assertJsonPath('upload.mode', 'proxy');

    $upload = AttachmentUpload::query()->findOrFail($createResponse->json('upload.id'));

    expect($upload->created_by_user_id)->toBeNull()
        ->and($upload->session_token_hash)->toBe(hash('sha256', $token));
});

test('访客上传路由接受显式独立会话词元', function () {
    $token = str_repeat('c', 32);
    $channel = Channel::factory()->create([
    ]);
    $file = UploadedFile::fake()->image('photo.png', 64, 64);

    $createResponse = $this->withHeader('X-Helmdesk-Visitor-Token', $token)
        ->postJson('/api/visitor/attachments/uploads', [
            'purpose' => 'conversation_image',
            'file_name' => 'photo.png',
            'mime_type' => 'image/png',
            'byte_size' => $file->getSize(),
            'context' => [
                'channel_code' => $channel->code,
                'session_token' => $token,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('upload.mode', 'proxy');

    $uploadId = $createResponse->json('upload.id');

    $this->withHeader('X-Helmdesk-Visitor-Token', $token)
        ->post('/api/visitor/attachments/uploads/'.$uploadId.'/proxy', ['file' => $file])
        ->assertOk();

    $this->withHeader('X-Helmdesk-Visitor-Token', $token)
        ->postJson('/api/visitor/attachments/uploads/'.$uploadId.'/complete')
        ->assertOk()
        ->assertJsonPath('attachment.status', 'uploaded');

    $upload = AttachmentUpload::query()->findOrFail($uploadId);

    expect($upload->session_token_hash)->toBe(hash('sha256', $token));
});

test('访客上传路由不需要Laravel CSRF会话', function () {
    $excludedPaths = app(ValidateCsrfToken::class)->getExcludedPaths();

    expect($excludedPaths)->toContain('api/visitor/attachments/*');
});

test('已完成上传不能中止在附件可读后', function () {
    $file = UploadedFile::fake()->createWithContent('note.txt', 'hello attachment');

    $createResponse = $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads', [
            'purpose' => 'conversation_file',
            'file_name' => 'note.txt',
            'mime_type' => 'text/plain',
            'byte_size' => $file->getSize(),
            'context' => [],
        ])
        ->assertOk();

    $uploadId = $createResponse->json('upload.id');
    $attachmentId = $createResponse->json('attachment.id');

    $this->actingAs($this->user)
        ->post('/api/attachments/uploads/'.$uploadId.'/proxy', ['file' => $file])
        ->assertOk();

    $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads/'.$uploadId.'/complete')
        ->assertOk();

    $this->actingAs($this->user)
        ->deleteJson('/api/attachments/uploads/'.$uploadId)
        ->assertUnprocessable();

    expect(Attachment::query()->findOrFail($attachmentId)->status)->toBe(AttachmentStatus::Uploaded);
});

test('已完成上传不能再次完成在附件已附加后', function () {
    $file = UploadedFile::fake()->createWithContent('note.txt', 'hello attachment');

    $createResponse = $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads', [
            'purpose' => 'conversation_file',
            'file_name' => 'note.txt',
            'mime_type' => 'text/plain',
            'byte_size' => $file->getSize(),
            'context' => [],
        ])
        ->assertOk();

    $uploadId = $createResponse->json('upload.id');
    $attachmentId = $createResponse->json('attachment.id');

    $this->actingAs($this->user)
        ->post('/api/attachments/uploads/'.$uploadId.'/proxy', ['file' => $file])
        ->assertOk();

    $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads/'.$uploadId.'/complete')
        ->assertOk();

    $attachment = Attachment::query()->findOrFail($attachmentId);
    $attachment->update([
        'status' => AttachmentStatus::Attached,
        'attachable_type' => $this->workspace->getMorphClass(),
        'attachable_id' => $this->workspace->id,
        'expires_at' => null,
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads/'.$uploadId.'/complete')
        ->assertUnprocessable();

    expect($attachment->fresh()->status)->toBe(AttachmentStatus::Attached)
        ->and($attachment->fresh()->expires_at)->toBeNull();
});

test('管理员和网页guard都已认证时系统级图片上传可以完成', function () {
    Storage::fake('local');

    $admin = createSuperAdmin();
    $webUser = User::factory()->create([
        'is_super_admin' => false,
    ]);
    $file = UploadedFile::fake()->image('logo.png', 120, 60);

    $this->actingAs($admin, 'admin');
    $this->actingAs($webUser, 'web');

    $createResponse = $this->postJson('/api/attachments/uploads', [
        'purpose' => 'channel_icon',
        'file_name' => 'logo.png',
        'mime_type' => 'image/png',
        'byte_size' => $file->getSize(),
        'context' => [],
    ])
        ->assertOk()
        ->assertJsonPath('upload.mode', 'proxy');

    $uploadId = $createResponse->json('upload.id');

    $this->post('/api/attachments/uploads/'.$uploadId.'/proxy', ['file' => $file])
        ->assertOk();

    $this->postJson('/api/attachments/uploads/'.$uploadId.'/complete')
        ->assertOk()
        ->assertJsonPath('attachment.status', 'uploaded');

    $attachment = Attachment::query()->findOrFail($createResponse->json('attachment.id'));

    Storage::disk('local')->assertExists($attachment->object_key);
});

test('自包含下载URL包含正确的文件名和MIME参数', function () {
    $profile = StorageProfile::factory()->local()->create();
    $attachment = Attachment::factory()->create([
        'storage_profile_id' => $profile->id,
        'disk' => 'local',
        'object_key' => 'workspaces/'.$this->workspace->id.'/avatar/screenshot.png',
        'original_name' => '截图2026-04-29.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 8,
        'status' => AttachmentStatus::Uploaded,
    ]);

    $urlResolver = app(AttachmentUrlResolver::class);
    $url = $urlResolver->url($attachment);

    expect($url)->toContain('mime=image%2Fpng')
        ->and($url)->toContain('name=');
    // 图片附件 TTL 为 2 小时；expires 对齐到 TTL/4 网格后剩余有效期至少为 3/4 TTL。
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);
    $remaining = (int) ($params['expires'] ?? 0) - now()->timestamp;
    expect($remaining)->toBeGreaterThan(7200 * 3 / 4)
        ->and($remaining)->toBeLessThanOrEqual(7200);
});

test('S3配置档会签发预签名POST、PUT和分片上传', function () {
    $profile = StorageProfile::factory()->create([
        'metadata' => [],
    ]);

    /** @var StorageSettings $settings */
    $settings = app(StorageSettings::class);
    $settings->enabled = true;
    $settings->current_profile_id = (string) $profile->id;
    $settings->save();

    $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads', [
            'purpose' => 'avatar',
            'file_name' => 'avatar.png',
            'mime_type' => 'image/png',
            'byte_size' => 1024,
            'context' => [],
        ])
        ->assertOk()
        ->assertJsonPath('upload.mode', 'presigned_post')
        ->assertJsonPath('direct.method', 'POST');

    $profile->update(['metadata' => ['direct_upload_mode' => 'presigned_put']]);

    $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads', [
            'purpose' => 'import',
            'file_name' => 'contacts.csv',
            'mime_type' => 'text/csv',
            'byte_size' => 2048,
            'context' => [],
        ])
        ->assertOk()
        ->assertJsonPath('upload.mode', 'presigned_put')
        ->assertJsonPath('direct.method', 'PUT')
        ->assertJsonPath('direct.headers.Content-Type', 'text/csv');

    $profile->update(['metadata' => []]);
    $client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => 'http://minio.test',
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'key', 'secret' => 'secret'],
        'handler' => new MockHandler([
            new Result(['UploadId' => 'multipart-1']),
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

    $multipartResponse = $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads', [
            'purpose' => 'conversation_file',
            'file_name' => 'manual.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => ValidateAttachmentUploadAction::MULTIPART_THRESHOLD + 1,
            'context' => [],
        ])
        ->assertOk()
        ->assertJsonPath('upload.mode', 'multipart')
        ->assertJsonPath('direct.upload_id', 'multipart-1')
        ->assertJsonPath('direct.part_size', ValidateAttachmentUploadAction::PART_SIZE);

    $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads/'.$multipartResponse->json('upload.id').'/parts', [
            'parts' => [1, 2],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'parts')
        ->assertJsonPath('parts.0.method', 'PUT');
});

test('图片完成时会尽可能创建WebP缩略图快照', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->image('photo.png', 800, 400);

    $createResponse = $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads', [
            'purpose' => 'conversation_image',
            'file_name' => 'photo.png',
            'mime_type' => 'image/png',
            'byte_size' => $file->getSize(),
            'context' => [],
        ])
        ->assertOk();

    $this->actingAs($this->user)
        ->post('/api/attachments/uploads/'.$createResponse->json('upload.id').'/proxy', ['file' => $file])
        ->assertOk();

    $this->actingAs($this->user)
        ->postJson('/api/attachments/uploads/'.$createResponse->json('upload.id').'/complete')
        ->assertOk();

    $attachment = Attachment::query()->findOrFail($createResponse->json('attachment.id'));
    $metadata = $attachment->metadata;

    expect($metadata['width'] ?? null)->toBe(800)
        ->and($metadata['height'] ?? null)->toBe(400)
        ->and($metadata['thumbnail_mime_type'] ?? null)->toBe('image/webp')
        ->and($metadata['thumbnail_key'] ?? null)->toBeString();

    Storage::disk('local')->assertExists($metadata['thumbnail_key']);
});

test('清理会删除过期代理上传对象并使上传意图过期', function () {
    $profile = StorageProfile::factory()->local()->create();
    $attachment = Attachment::factory()->create([
        'storage_profile_id' => $profile->id,
        'status' => AttachmentStatus::Pending,
        'object_key' => 'workspaces/'.$this->workspace->id.'/conversation_file/stale.txt',
    ]);
    $upload = AttachmentUpload::factory()->create([
        'attachment_id' => $attachment->id,
        'storage_profile_id' => $profile->id,
        'status' => AttachmentUploadStatus::Uploading,
        'mode' => AttachmentUploadMode::Proxy,
        'object_key' => $attachment->object_key,
        'expires_at' => now()->subMinute(),
    ]);
    Storage::disk('local')->put($attachment->object_key, 'stale');

    $this->artisan('attachments:cleanup')->assertSuccessful();

    expect($upload->fresh()->status)->toBe(AttachmentUploadStatus::Expired)
        ->and(Attachment::withTrashed()->findOrFail($attachment->id)->status)->toBe(AttachmentStatus::Deleted)
        ->and(Attachment::withTrashed()->findOrFail($attachment->id)->trashed())->toBeTrue();
    Storage::disk('local')->assertMissing($attachment->object_key);
});

test('清理删除过期公开孤立附件', function () {
    $profile = StorageProfile::factory()->local()->create();
    $attachment = Attachment::factory()->create([
        'storage_profile_id' => $profile->id,
        'status' => AttachmentStatus::Uploaded,
        'visibility' => 'public',
        'object_key' => 'workspaces/'.$this->workspace->id.'/avatar/orphan.png',
        'uploaded_at' => now()->subDays(2),
        'expires_at' => now()->subMinute(),
        'attachable_id' => null,
        'attachable_type' => null,
    ]);
    Storage::disk('local')->put($attachment->object_key, 'orphan');

    $this->artisan('attachments:cleanup')->assertSuccessful();

    $deleted = Attachment::withTrashed()->findOrFail($attachment->id);

    expect($deleted->status)->toBe(AttachmentStatus::Deleted)
        ->and($deleted->trashed())->toBeTrue();
    Storage::disk('local')->assertMissing($attachment->object_key);
});

test('中止过期分片命令会取消远程上传并使记录过期', function () {
    $profile = StorageProfile::factory()->create();
    $attachment = Attachment::factory()->create([
        'storage_profile_id' => $profile->id,
        'disk' => 's3',
        'bucket' => $profile->bucket,
        'status' => AttachmentStatus::Pending,
    ]);
    $upload = AttachmentUpload::factory()->create([
        'attachment_id' => $attachment->id,
        'storage_profile_id' => $profile->id,
        'status' => AttachmentUploadStatus::Uploading,
        'mode' => AttachmentUploadMode::Multipart,
        'object_key' => $attachment->object_key,
        'upload_id' => 'multipart-to-abort',
        'expires_at' => now()->subMinute(),
    ]);
    $client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => 'http://minio.test',
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'key', 'secret' => 'secret'],
        'handler' => new MockHandler([
            new Result([]),
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

    $this->artisan('attachments:abort-expired-multipart')->assertSuccessful();

    expect($upload->fresh()->status)->toBe(AttachmentUploadStatus::Expired)
        ->and($attachment->fresh()->status)->toBe(AttachmentStatus::Expired);
});
