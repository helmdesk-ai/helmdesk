<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Telegram Bot Token：高敏凭证，用模型 encrypted cast 加密落库，不与 settings JSON 混存。
            // 其余 Telegram 配置（webhook_secret / bot 信息 / 默认语言）沿用 settings JSON。
            $table->text('telegram_bot_token')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('telegram_bot_token');
        });
    }
};
