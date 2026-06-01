<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamp('created_at')->nullable();
            $table->ulid('workspace_id');
            $table->ulid('conversation_id');
            $table->ulid('actor_user_id')->nullable();
            $table->string('type', 30);
            $table->json('payload')->nullable();

            $table->index(['conversation_id', 'created_at', 'id'], 'conversation_events_timeline_idx');
            $table->index(['conversation_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_events');
    }
};
