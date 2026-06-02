<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->ulid('attachment_id');
            $table->ulid('storage_profile_id');
            $table->string('mode', 30);
            $table->string('status', 20)->default('pending');
            $table->string('object_key');
            $table->string('upload_id')->nullable();
            $table->unsignedBigInteger('part_size')->nullable();
            $table->json('parts')->nullable();
            $table->string('expected_name');
            $table->string('expected_mime_type');
            $table->unsignedBigInteger('expected_byte_size');
            $table->string('expected_checksum_sha256', 64)->nullable();
            $table->ulid('created_by_user_id')->nullable();
            $table->string('session_token_hash')->nullable();
            $table->string('client_ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();

            $table->index('attachment_id');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_uploads');
    }
};
