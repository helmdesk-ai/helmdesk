<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->softDeletes();
            $table->ulid('uploaded_by_user_id')->nullable();
            $table->ulid('storage_profile_id');
            $table->string('disk', 20);
            $table->string('bucket')->nullable();
            $table->string('object_key');
            $table->string('original_name');
            $table->string('mime_type');
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('etag')->nullable();
            $table->string('visibility', 20)->default('private');
            $table->string('purpose', 40)->default('other');
            $table->string('status', 20)->default('pending');
            $table->nullableUlidMorphs('attachable');
            $table->json('metadata')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('attached_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->index(['purpose', 'status']);
            $table->index(['storage_profile_id', 'status']);
            $table->index('expires_at');
            $table->unique(['storage_profile_id', 'object_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
