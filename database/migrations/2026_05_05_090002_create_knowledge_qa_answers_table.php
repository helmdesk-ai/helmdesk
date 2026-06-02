<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_qa_answers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('knowledge_qa_entry_id');
            $table->longText('answer');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->index(['knowledge_qa_entry_id', 'sort_order'], 'idx_kb_qa_answer_entry_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_qa_answers');
    }
};
