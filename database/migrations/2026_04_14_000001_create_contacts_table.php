<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->softDeletes();
            $table->string('type')->default('visitor');
            $table->string('source')->default('web');
            $table->string('name')->nullable();
            $table->string('avatar_url')->default('/images/default-avatar.svg');
            $table->timestamp('avatar_synced_at')->nullable();
            $table->string('locale')->nullable();
            $table->string('timezone')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('primary_email')->nullable();
            $table->string('primary_phone')->nullable();
            $table->json('ai_context')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_important')->default(false);
            $table->timestamp('important_at')->nullable();
            $table->ulid('important_by_user_id')->nullable();
            $table->string('important_source', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->index(['deleted_at', 'type', 'is_important', 'last_seen_at', 'created_at'], 'contacts_type_list_idx');
            $table->index(['deleted_at', 'is_important', 'last_seen_at', 'created_at'], 'contacts_list_idx');
            $table->index(['deleted_at', 'created_at'], 'contacts_trash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
