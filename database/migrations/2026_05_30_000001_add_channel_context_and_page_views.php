<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 会话级渠道上下文快照：按渠道类型存不同结构（Web 访客行为 / Telegram 用户元数据），
        // 由 ConversationChannelContextCast 依据 json 内的 channel_type 判别字段分流。
        Schema::table('conversations', function (Blueprint $table) {
            $table->json('channel_context')->nullable()->after('ai_context');
        });

        // 访客浏览轨迹：每条会话内多条页面访问记录，时间序列，单独成表。
        Schema::create('conversation_page_views', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->ulid('workspace_id');
            $table->ulid('conversation_id');
            $table->ulid('contact_id')->nullable();
            $table->text('url');
            $table->string('title')->nullable();
            $table->text('referrer')->nullable();
            $table->timestamp('viewed_at');

            $table->index(['workspace_id', 'conversation_id', 'viewed_at'], 'page_views_conversation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_page_views');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('channel_context');
        });
    }
};
