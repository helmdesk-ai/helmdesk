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
            $table->ulid('reception_plan_id')->nullable();
            $table->ulid('reception_plan_version_id')->nullable();
            $table->json('settings')->nullable();
            // Telegram Bot Token：高敏凭证，用模型 encrypted cast 加密落库，不与 settings JSON 混存。
            // 其余 Telegram 配置（webhook_secret / bot 信息 / 默认语言）沿用 settings JSON。
            $table->text('telegram_bot_token')->nullable();

            $table->string('first_embed_host', 255)->nullable();
            $table->timestamp('first_embed_at')->nullable();
            $table->string('last_embed_host', 255)->nullable();
            $table->timestamp('last_embed_at')->nullable();

            $table->index(['type', 'deleted_at', 'created_at'], 'idx_channels_type_deleted_created');
            $table->index('reception_plan_id', 'idx_channels_plan');
            $table->index('reception_plan_version_id', 'idx_channels_plan_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
