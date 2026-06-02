<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_qa_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('knowledge_base_id');
            $table->ulid('group_id');
            $table->ulid('created_by_user_id')->nullable();

            $table->string('question', 500);
            $table->string('status', 32)->default('pending');
            $table->text('error_message')->nullable();
            $table->string('vector_status', 32)->default('idle');
            $table->text('vector_error')->nullable();
            $table->timestamp('vector_indexed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->index(['knowledge_base_id', 'group_id'], 'idx_kb_qa_entry_kb_group');
            $table->index(['knowledge_base_id', 'status'], 'idx_kb_qa_entry_kb_status');
            $table->index('created_at', 'idx_kb_qa_entry_created_at');
            $table->index(['knowledge_base_id', 'question'], 'idx_kb_qa_entry_kb_question');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_qa_entries');
    }
};
