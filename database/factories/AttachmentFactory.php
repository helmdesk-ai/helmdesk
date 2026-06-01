<?php

namespace Database\Factories;

use App\Enums\AttachmentPurpose;
use App\Enums\AttachmentStatus;
use App\Enums\AttachmentVisibility;
use App\Enums\StorageDriver;
use App\Models\Attachment;
use App\Models\StorageProfile;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'storage_profile_id' => StorageProfile::factory()->local(),
            'disk' => StorageDriver::Local,
            'object_key' => 'workspaces/'.fake()->uuid().'/conversation_file/'.fake()->uuid().'.txt',
            'original_name' => 'note.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'byte_size' => 12,
            'visibility' => AttachmentVisibility::Private,
            'purpose' => AttachmentPurpose::ConversationFile,
            'status' => AttachmentStatus::Uploaded,
            'metadata' => [],
            'uploaded_at' => now(),
        ];
    }
}
