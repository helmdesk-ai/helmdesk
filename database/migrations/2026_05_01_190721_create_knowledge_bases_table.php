<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('workspace_id');
            $table->string('name');
            $table->string('category')->default('standard');
            $table->ulid('avatar_id')->nullable();
            $table->text('description')->nullable();

            $table->index(['workspace_id', 'name'], 'idx_kb_workspace_name');
            $table->unique(['workspace_id', 'name'], 'uniq_kb_workspace_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_bases');
    }
};
