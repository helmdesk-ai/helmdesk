<?php

namespace App\Models;

use App\Enums\AttachmentPurpose;
use App\Enums\AttachmentStatus;
use App\Enums\AttachmentVisibility;
use App\Enums\StorageDriver;
use App\Services\Storage\AttachmentUrlResolver;
use App\Services\Storage\StorageProfileDisk;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property string|null $workspace_id
 * @property string|null $uploaded_by_user_id
 * @property string $storage_profile_id
 * @property \App\Enums\StorageDriver $disk
 * @property string|null $bucket
 * @property string $object_key
 * @property string $original_name
 * @property string $mime_type
 * @property string|null $extension
 * @property int $byte_size
 * @property string|null $checksum_sha256
 * @property string|null $etag
 * @property \App\Enums\AttachmentVisibility $visibility
 * @property \App\Enums\AttachmentPurpose $purpose
 * @property \App\Enums\AttachmentStatus $status
 * @property string|null $attachable_type
 * @property string|null $attachable_id
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $uploaded_at
 * @property \Illuminate\Support\Carbon|null $attached_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string $full_url
 * @property ?string $preview_url
 * @property mixed $use_factory
 * @property int|null $attachables_count
 * @property int|null $workspaces_count
 * @property int|null $uploaded_bies_count
 * @property int|null $storage_profiles_count
 *
 * @property-read \Illuminate\Database\Eloquent\Model|null $attachable
 * @property-read \App\Models\Workspace|null $workspace
 * @property-read \App\Models\User|null $uploadedBy
 * @property-read \App\Models\StorageProfile $storageProfile
 *
 * @method static \Database\Factories\AttachmentFactory<self> factory($count = null, $state = [])
 */
class Attachment extends Model
{
    /**
     * 附件模型，记录上传文件的存储位置、归属对象和访问地址。
     */
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'attachments';

    protected $guarded = [];

    protected $appends = [
        'full_url',
        'preview_url',
    ];

    /**
     * 返回附件字段的类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disk' => StorageDriver::class,
            'visibility' => AttachmentVisibility::class,
            'purpose' => AttachmentPurpose::class,
            'status' => AttachmentStatus::class,
            'byte_size' => 'integer',
            'metadata' => 'array',
            'uploaded_at' => 'datetime',
            'attached_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * 关联附件绑定的业务模型。
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 解析附件完整访问地址。
     */
    public function getFullUrlAttribute(): string
    {
        return app(AttachmentUrlResolver::class)->url($this);
    }

    /**
     * 解析附件预览图访问地址。
     */
    public function getPreviewUrlAttribute(): ?string
    {
        return app(AttachmentUrlResolver::class)->previewUrl($this);
    }

    /**
     * 关联附件所属工作区。
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * 关联上传附件的用户。
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id')->withTrashed();
    }

    /**
     * 关联附件使用的存储配置。
     */
    public function storageProfile(): BelongsTo
    {
        return $this->belongsTo(StorageProfile::class, 'storage_profile_id');
    }

    /**
     * 按 ID 查找附件的公开访问地址。
     */
    public static function findUrl(?string $id): ?string
    {
        if (! filled($id)) {
            return null;
        }

        return static::query()->find($id)?->full_url;
    }

    /**
     * 构建可读取附件对象的文件系统实例。
     */
    public function filesystem(): FilesystemAdapter
    {
        if ($this->storage_profile_id) {
            $profile = $this->relationLoaded('storageProfile')
                ? $this->storageProfile
                : $this->storageProfile()->first();

            if ($profile) {
                return StorageProfileDisk::build($profile);
            }
        }

        return Storage::disk($this->disk->value);
    }
}
