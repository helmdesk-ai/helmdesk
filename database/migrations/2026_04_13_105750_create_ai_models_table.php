<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('ai_provider_id');
            $table->string('model_id');
            $table->string('name');
            $table->string('type');              // llm, embedding, rerank
            $table->boolean('is_active')->default(true);
            $table->boolean('is_builtin')->default(false);
            $table->integer('sort_order')->default(0);

            $table->unique(['ai_provider_id', 'model_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
