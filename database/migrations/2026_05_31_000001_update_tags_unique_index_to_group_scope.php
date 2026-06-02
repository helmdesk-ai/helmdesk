<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 确保标签名称唯一性收敛到同一标签组内。
     */
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS tags_workspace_normalized_name_active_unique');
        DB::statement('DROP INDEX IF EXISTS tags_workspace_group_normalized_name_active_unique');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS tags_group_normalized_name_active_unique ON tags (tag_group_id, normalized_name) WHERE deleted_at IS NULL',
        );
    }

    /**
     * 回滚时仍保留单租户下的标签组内唯一约束。
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tags_group_normalized_name_active_unique');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS tags_group_normalized_name_active_unique ON tags (tag_group_id, normalized_name) WHERE deleted_at IS NULL',
        );
    }
};
