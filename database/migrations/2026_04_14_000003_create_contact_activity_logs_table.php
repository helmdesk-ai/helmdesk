<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_activity_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamp('created_at')->nullable();
            $table->ulid('workspace_id');
            $table->ulid('contact_id');
            $table->ulid('related_contact_id')->nullable();
            $table->ulid('actor_user_id')->nullable();
            $table->string('action');
            $table->json('payload')->nullable();

            $table->index('workspace_id');
            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_activity_logs');
    }
};
