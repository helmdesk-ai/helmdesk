<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->ulid('workspace_id');
            $table->string('key', 50);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('type', 30);
            $table->json('config')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_api_writable')->default(true);
            $table->boolean('is_ai_readable')->default(true);
            $table->boolean('is_ai_writable')->default(false);
            $table->softDeletes();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'key']);
            $table->index(['workspace_id', 'deleted_at', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
