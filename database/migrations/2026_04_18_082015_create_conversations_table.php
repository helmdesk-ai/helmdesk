<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->ulid('contact_id')->nullable();
            $table->ulid('assigned_user_id')->nullable();
            $table->ulid('channel_id')->nullable();
            $table->ulid('reception_plan_version_id')->nullable();
            $table->string('entry_mode', 20)->nullable();
            $table->string('visitor_locale', 10)->default('zh-CN');
            $table->string('source', 20)->default('manual');
            $table->string('status', 20)->default('open');
            $table->string('inbox_status', 30)->default('ai_handling');
            $table->boolean('waiting_for_visitor_reply')->default(false);
            $table->string('subject')->nullable();
            $table->text('summary')->nullable();
            $table->string('summary_locale', 20)->nullable();
            $table->json('summary_translations')->nullable();
            $table->unsignedBigInteger('summary_last_message_seq_no')->default(0);
            $table->timestamp('summary_generated_at')->nullable();
            $table->json('ai_context')->nullable();
            $table->string('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_visitor_message_count')->default(0);
            $table->unsignedInteger('unread_agent_message_count')->default(0);
            // 会话内消息单调序号分发计数器；发消息时通过 UPDATE ... = next_seq_no + 1 原子递增。
            $table->unsignedBigInteger('next_seq_no')->default(0);
            $table->timestamp('closed_at')->nullable();

            $table->index(['status', 'inbox_status', 'last_message_at'], 'conversations_inbox_idx');
            $table->index(['status', 'waiting_for_visitor_reply', 'last_message_at'], 'conversations_waiting_visitor_idx');
            $table->index(['assigned_user_id', 'status', 'last_message_at'], 'conversations_assigned_idx');
            $table->index('contact_id');
            $table->index('channel_id');
            $table->index('reception_plan_version_id', 'idx_conversations_plan_version');
        });

        // 同一 channel + contact 在任意时刻最多只有一条 open 会话。
        // SQLite 的 partial unique index 刚好覆盖"关闭后可以再开一条"这个业务语义。
        DB::statement(
            'CREATE UNIQUE INDEX conversations_one_open_per_contact_channel '.
            'ON conversations (channel_id, contact_id) '.
            "WHERE status = 'open' AND contact_id IS NOT NULL AND channel_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS conversations_one_open_per_contact_channel');
        Schema::dropIfExists('conversations');
    }
};
