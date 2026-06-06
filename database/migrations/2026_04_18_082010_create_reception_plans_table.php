<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->softDeletes();

            $table->string('name');
            $table->text('description')->nullable();

            $table->json('persona_config')->nullable();
            $table->text('global_instructions')->nullable();
            $table->json('reception_config')->nullable();
            $table->json('task_config')->nullable();
            $table->json('capabilities')->default('[]');
            $table->json('always_on_tools')->default('[]');
            $table->json('knowledge_base_ids')->default('[]');
            $table->json('strategy_config');
            $table->json('auto_messages_config');
            $table->json('translation_config')->nullable();

            $table->unique('name', 'uniq_reception_plans_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_plans');
    }
};
