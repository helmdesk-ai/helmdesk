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
        Schema::create('workspaces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->string('name')->comment('工作区名');
            $table->string('slug')->nullable()->unique()->comment('访问标识');
            $table->ulid('logo_id')->nullable()->comment('工作区logo');
            $table->ulid('owner_id')->nullable()->comment('所有者ID');
            $table->ulid('knowledge_embedding_model_id')->nullable();
            $table->ulid('knowledge_rerank_model_id')->nullable();
            $table->ulid('knowledge_summary_model_id')->nullable();
            $table->unsignedSmallInteger('knowledge_embedding_dimension')->nullable();
            $table->boolean('knowledge_vector_index_enabled')->default(false);
            $table->boolean('knowledge_raptor_index_enabled')->default(false);
            $table->string('knowledge_chunking_strategy', 16)->default('fixed');
            $table->unsignedSmallInteger('knowledge_chunk_max_tokens')->default(512);
            $table->unsignedSmallInteger('knowledge_chunk_overlap_tokens')->default(64);
            $table->softDeletes();

            $table->index('knowledge_embedding_model_id');
            $table->index('knowledge_rerank_model_id');
            $table->index('knowledge_summary_model_id');
            $table->index('knowledge_vector_index_enabled');
            $table->index('knowledge_raptor_index_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
