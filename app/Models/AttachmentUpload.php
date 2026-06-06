<?php

namespace App\Models;

use App\Enums\AttachmentUploadMode;
use App\Enums\AttachmentUploadStatus;
use Database\Factories\AttachmentUploadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $attachment_id
 * @property string $storage_profile_id
 * @property AttachmentUploadMode $mode
 * @property AttachmentUploadStatus $status
 * @property string $object_key
 * @property string $expected_name
 * @property string $expected_mime_type
 * @property int $expected_byte_size
 * @property string|null $expected_checksum_sha256
 * @property string|null $created_by_user_id
 * @property string|null $session_token_hash
 * @property string|null $client_ip
 * @property string|null $user_agent
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property mixed $use_factory
 * @property int|null $attachments_count
 * @property int|null $storage_profiles_count
 * @property-read Attachment $attachment
 * @property-read StorageProfile $storageProfile
 *
 * @method static \Database\Factories\AttachmentUploadFactory<self> factory($count = null, $state = [])
 */
class AttachmentUpload extends Model
{
    /**
     * 附件上传意图模型，保存直传参数、校验期望值和上传归属。
     */

    /** @use HasFactory<AttachmentUploadFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * 返回上传意图字段的类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => AttachmentUploadMode::class,
            'status' => AttachmentUploadStatus::class,
            'expected_byte_size' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * 关联上传意图对应的附件占位记录。
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /**
     * 关联上传意图使用的存储配置。
     */
    public function storageProfile(): BelongsTo
    {
        return $this->belongsTo(StorageProfile::class);
    }
}
