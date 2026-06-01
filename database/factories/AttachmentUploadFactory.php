<?php

namespace Database\Factories;

use App\Enums\AttachmentUploadMode;
use App\Enums\AttachmentUploadStatus;
use App\Models\Attachment;
use App\Models\AttachmentUpload;
use App\Models\StorageProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttachmentUpload>
 */
class AttachmentUploadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachment_id' => Attachment::factory(),
            'storage_profile_id' => StorageProfile::factory(),
            'mode' => AttachmentUploadMode::Proxy,
            'status' => AttachmentUploadStatus::Pending,
            'object_key' => 'attachments/'.fake()->uuid().'.txt',
            'expected_name' => 'note.txt',
            'expected_mime_type' => 'text/plain',
            'expected_byte_size' => 12,
            'expires_at' => now()->addHour(),
        ];
    }
}
