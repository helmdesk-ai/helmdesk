<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->softDeletes();

            $table->string('type');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('code')->unique();
            $table->ulid('reception_plan_version_id')->nullable();
            $table->json('settings')->nullable();

            $table->string('first_embed_host', 255)->nullable()->after('settings');
            $table->timestamp('first_embed_at')->nullable()->after('first_embed_host');
            $table->string('last_embed_host', 255)->nullable()->after('first_embed_at');
            $table->timestamp('last_embed_at')->nullable()->after('last_embed_host');

            $table->index('type');
            $table->index('reception_plan_version_id', 'idx_channels_plan_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
