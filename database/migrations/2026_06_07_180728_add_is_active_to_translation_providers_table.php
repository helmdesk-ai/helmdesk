<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('translation_providers', function (Blueprint $table) {
            // 启用开关：仅启用且凭据完整的供应商进入运行时翻译轮询池（见 TranslationProviderPool）。
            $table->boolean('is_active')->default(true)->after('options');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('translation_providers', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
