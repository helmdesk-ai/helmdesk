<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_qa_questions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('knowledge_qa_entry_id');
            $table->string('question', 500);
            $table->unsignedInteger('sort_order')->default(0);

            $table->index('knowledge_qa_entry_id', 'idx_kb_qa_question_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_qa_questions');
    }
};
