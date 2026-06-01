<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('workspace_id');
            $table->ulid('knowledge_base_id');
            $table->ulid('group_id');
            $table->ulid('uploaded_by_user_id')->nullable();

            $table->string('original_filename');
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('byte_size');
            $table->string('extension', 16)->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('source_type', 32)->default('upload');
            $table->string('status', 32)->default('pending');
            $table->text('error_message')->nullable();
            $table->longText('content')->nullable();
            $table->string('parse_status', 32)->default('pending');
            $table->text('parse_error')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->string('parsed_content_format', 16)->nullable();
            $table->longText('parsed_content')->nullable();
            $table->json('parse_metadata')->nullable();

            $table->string('vector_status', 32)->default('idle');
            $table->text('vector_error')->nullable();
            $table->timestamp('vector_indexed_at')->nullable();

            $table->string('raptor_status', 32)->default('idle');
            $table->text('raptor_error')->nullable();
            $table->timestamp('raptor_indexed_at')->nullable();

            $table->index(['knowledge_base_id', 'group_id'], 'idx_kb_doc_kb_group');
            $table->index(['workspace_id', 'created_at'], 'idx_kb_doc_workspace_created_at');
            $table->index(['knowledge_base_id', 'parse_status'], 'idx_kb_doc_kb_parse_status');
            $table->index(['knowledge_base_id', 'vector_status'], 'idx_kb_doc_kb_vector_status');
            $table->index(['knowledge_base_id', 'raptor_status'], 'idx_kb_doc_kb_raptor_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
