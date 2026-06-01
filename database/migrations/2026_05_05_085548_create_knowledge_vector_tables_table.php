<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 向量表按维度动态建表，knowledge_vector_tables 保存维度 → 物理表名的注册表。
        // 重建迁移前先清理掉历史注册表里登记的所有向量物理表，避免遗留孤儿表。
        if (Schema::connection('sqlite_rag')->hasTable('knowledge_vector_tables')) {
            $registered = DB::connection('sqlite_rag')->table('knowledge_vector_tables')->pluck('table_name');
            foreach ($registered as $tableName) {
                DB::connection('sqlite_rag')->statement('DROP TABLE IF EXISTS '.$tableName);
            }
            DB::connection('sqlite_rag')->statement('DROP TABLE IF EXISTS knowledge_vector_tables');
        }

        DB::connection('sqlite_rag')->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS knowledge_vector_tables (
                dimension INTEGER PRIMARY KEY,
                table_name TEXT NOT NULL,
                created_at DATETIME NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        if (Schema::connection('sqlite_rag')->hasTable('knowledge_vector_tables')) {
            $registered = DB::connection('sqlite_rag')->table('knowledge_vector_tables')->pluck('table_name');
            foreach ($registered as $tableName) {
                DB::connection('sqlite_rag')->statement('DROP TABLE IF EXISTS '.$tableName);
            }
        }

        DB::connection('sqlite_rag')->statement('DROP TABLE IF EXISTS knowledge_vector_tables');
    }
};
