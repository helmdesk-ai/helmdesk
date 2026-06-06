<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_replies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->softDeletes();

            // user_id 为 NULL 表示系统共享；非 NULL 表示该用户私有。
            // 用户被删除时由 App\Models\User 的 forceDeleted 钩子负责清理其私有模版。
            $table->ulid('user_id')->nullable();

            $table->string('name', 120);
            $table->string('shortcut', 32)->nullable();
            $table->text('content');

            $table->unsignedBigInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            // AI 留口：embedding 元数据、AI 生成标记等向后扩展都丢这里。
            $table->json('metadata')->nullable();

            $table->ulid('created_by_user_id')->nullable();
            $table->ulid('updated_by_user_id')->nullable();

            $table->index(['user_id', 'last_used_at', 'usage_count', 'name'], 'canned_replies_owner_usage_idx');
        });

        // 同归属（个人/共享）下，shortcut 不重复。
        // SQLite 中 NULL 不参与唯一约束，使用 COALESCE 把 user_id 归一化。
        DB::statement(
            'CREATE UNIQUE INDEX uniq_canned_replies_shortcut '
            ."ON canned_replies (COALESCE(user_id, ''), shortcut) "
            .'WHERE deleted_at IS NULL AND shortcut IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
    }
};
