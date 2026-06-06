<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_auto_message_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->ulid('conversation_id');
            $table->string('trigger', 50);
            $table->string('idempotency_key', 120);
            $table->ulid('actor_user_id')->nullable();
            $table->ulid('conversation_event_id')->nullable();
            $table->ulid('message_id')->nullable();

            $table->unique(['conversation_id', 'idempotency_key'], 'conversation_auto_messages_once_unique');
            $table->index('message_id', 'conversation_auto_messages_message_idx');
            $table->index('conversation_event_id', 'conversation_auto_messages_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_auto_message_receipts');
    }
};
