<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sqlite_rag')->create('knowledge_outlines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('knowledge_base_id');
            $table->ulid('document_id');

            $table->json('outline');

            $table->unique('document_id', 'uniq_kn_outline_doc');
            $table->index(['knowledge_base_id'], 'idx_kn_outline_kb');
        });
    }

    public function down(): void
    {
        Schema::connection('sqlite_rag')->dropIfExists('knowledge_outlines');
    }
};
