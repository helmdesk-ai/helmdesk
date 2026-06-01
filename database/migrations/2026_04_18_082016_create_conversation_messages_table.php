<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->ulid('workspace_id');
            $table->ulid('conversation_id');
            $table->ulid('sender_user_id')->nullable();
            $table->string('sender_name')->default('');
            $table->string('role', 20);
            $table->string('kind', 20);
            $table->text('content')->nullable();
            $table->string('content_locale', 20)->nullable();
            $table->json('payload')->nullable();
            $table->float('confidence')->nullable();
            // 客户端生成的幂等键，用于在弱网重发时去重，仅对来自外部端（widget/agent UI）的消息有意义。
            $table->string('client_msg_id', 64)->nullable();
            // 会话内单调序号，由 Conversation.next_seq_no 原子分发；前端按此排序、去重、补洞。
            $table->unsignedBigInteger('seq_no');
            $table->string('delivery_status', 20)->default('sent');
            $table->ulid('quoted_message_id')->nullable();
            $table->timestamp('recalled_at')->nullable();

            $table->index(['conversation_id', 'created_at', 'id'], 'conversation_messages_timeline_idx');
            $table->index(['workspace_id', 'created_at'], 'conversation_messages_workspace_idx');
            $table->index(['conversation_id', 'workspace_id']);
            $table->unique(['conversation_id', 'seq_no'], 'conversation_messages_seq_unique');
            $table->index('quoted_message_id', 'conversation_messages_quoted_idx');
        });

        // SQLite partial unique index：仅当 client_msg_id 非空时强制 (conversation_id, client_msg_id) 唯一，
        // 保证来自客户端的幂等键去重，同时不影响服务端内部产生的消息（如 AI、系统消息）。
        DB::statement(
            'CREATE UNIQUE INDEX conversation_messages_client_msg_unique '.
            'ON conversation_messages (conversation_id, client_msg_id) '.
            'WHERE client_msg_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        // up() 里手写了 partial unique index，dropIfExists 一般会把它带走；
        // 显式 DROP INDEX 更稳妥，避免极端情况下表已被先删而索引留作孤儿。
        DB::statement('DROP INDEX IF EXISTS conversation_messages_client_msg_unique');
        Schema::dropIfExists('conversation_messages');
    }
};
