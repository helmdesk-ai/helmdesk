<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_timeline_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->ulid('workspace_id');
            $table->ulid('contact_id')->nullable();
            $table->ulid('conversation_id');
            $table->string('entry_type', 20);
            $table->ulid('entry_id');
            $table->timestamp('occurred_at');

            $table->unique(['entry_type', 'entry_id'], 'conversation_timeline_entry_unique');
            $table->index(['workspace_id', 'contact_id', 'occurred_at', 'id'], 'conversation_timeline_contact_idx');
            $table->index(['workspace_id', 'conversation_id', 'occurred_at', 'id'], 'conversation_timeline_conversation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_timeline_entries');
    }
};
