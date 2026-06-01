<?php

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\StorageProfile;
use App\Services\Storage\AttachmentUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('同一对齐窗口内返回相同的 S3 预签名 URL', function () {
    $profile = StorageProfile::factory()->create();
    $attachment = Attachment::factory()->create([
        'storage_profile_id' => $profile->id,
        'disk' => $profile->driver,
        'object_key' => 'workspaces/test/conversation_image/sample.png',
        'original_name' => 'sample.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 100,
        'status' => AttachmentStatus::Uploaded,
    ]);

    $resolver = app(AttachmentUrlResolver::class);

    $first = $resolver->url($attachment);
    $second = $resolver->url($attachment);

    expect($first)->toBe($second)
        ->and($first)->toContain('X-Amz-Signature=')
        ->and($first)->toContain('X-Amz-Expires=7200');
});

it('图片 S3 URL 强制 inline content-disposition 且文件使用 attachment', function () {
    $profile = StorageProfile::factory()->create();
    $image = Attachment::factory()->create([
        'storage_profile_id' => $profile->id,
        'disk' => $profile->driver,
        'object_key' => 'workspaces/test/photo.png',
        'original_name' => 'photo.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 100,
        'status' => AttachmentStatus::Uploaded,
    ]);
    $document = Attachment::factory()->create([
        'storage_profile_id' => $profile->id,
        'disk' => $profile->driver,
        'object_key' => 'workspaces/test/note.pdf',
        'original_name' => 'note.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'byte_size' => 100,
        'status' => AttachmentStatus::Uploaded,
    ]);

    $resolver = app(AttachmentUrlResolver::class);

    expect($resolver->url($image))->toContain('response-content-disposition=inline')
        ->and($resolver->url($image))->toContain('response-content-type=image%2Fpng')
        ->and($resolver->url($document))->toContain('response-content-disposition=attachment');
});

it('跨不同对齐窗口生成不同的 S3 URL', function () {
    $profile = StorageProfile::factory()->create();
    $attachment = Attachment::factory()->create([
        'storage_profile_id' => $profile->id,
        'disk' => $profile->driver,
        'object_key' => 'workspaces/test/conversation_image/sample.png',
        'original_name' => 'sample.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 100,
        'status' => AttachmentStatus::Uploaded,
    ]);

    $resolver = app(AttachmentUrlResolver::class);

    $now = now();
    $this->travelTo($now);
    $first = $resolver->url($attachment);

    // 图片 TTL=7200，alignment step = 1800s（30 分钟）。跨过窗口边界后签名必然变化。
    $this->travelTo($now->copy()->addMinutes(31));
    $second = $resolver->url($attachment);

    expect($first)->not->toBe($second);
});
