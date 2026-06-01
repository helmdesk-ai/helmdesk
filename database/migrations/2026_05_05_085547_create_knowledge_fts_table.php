<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('sqlite_rag')->statement(<<<'SQL'
            CREATE VIRTUAL TABLE IF NOT EXISTS knowledge_fts USING fts5(
                search_content,
                heading_path,
                content UNINDEXED,
                node_id UNINDEXED,
                document_id UNINDEXED,
                qa_entry_id UNINDEXED,
                qa_question_id UNINDEXED,
                knowledge_base_id UNINDEXED,
                workspace_id UNINDEXED,
                group_id UNINDEXED,
                byte_start UNINDEXED,
                byte_end UNINDEXED,
                tokenize = 'unicode61'
            )
        SQL);
    }

    public function down(): void
    {
        DB::connection('sqlite_rag')->statement('DROP TABLE IF EXISTS knowledge_fts');
    }
};
