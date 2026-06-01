<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->string('name');
            $table->string('driver', 20)->default('s3');
            $table->string('provider')->nullable(); // \App\Enums\StorageProvider value
            $table->string('status', 20)->default('active');

            $table->text('access_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->text('session_token')->nullable();

            $table->string('bucket')->nullable();
            $table->string('region')->nullable();
            $table->string('endpoint')->nullable();
            $table->string('public_url')->nullable();
            $table->string('upload_endpoint')->nullable();
            $table->string('download_endpoint')->nullable();
            $table->boolean('force_path_style')->default(false);
            $table->string('signature_version', 20)->default('s3v4');
            $table->unsignedBigInteger('max_upload_size')->nullable();
            $table->json('allowed_mime_types')->nullable();
            $table->json('metadata')->nullable();

            $table->index(['driver', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_profiles');
    }
};
