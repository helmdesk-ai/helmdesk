<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->string('slug');
            $table->string('name');
            $table->string('protocol');
            $table->string('icon')->nullable();
            $table->text('credentials')->nullable();
            $table->json('credential_fields');
            // Provider 特有运行参数（例如 DeepL 的 formality、Google 的 model variant、术语表 id），
            // 与 credentials 分开是为了让非敏感配置可直接写入 / 序列化给前端展示。
            $table->json('options')->nullable();
            // 供应商只是「已配置凭据的目录」，用哪家由接待方案的 translation_config.provider_id 决定。
            $table->boolean('is_builtin')->default(true);
            $table->integer('sort_order')->default(0);

            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_providers');
    }
};
