<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->ulid('reception_plan_id')->nullable()->after('code');
            $table->index(['workspace_id', 'reception_plan_id'], 'idx_channels_workspace_plan');
        });

        // 把渠道从「绑定具体版本」回填为「绑定方案」：由当前 reception_plan_version_id
        // 反查所属方案写入 reception_plan_id，使渠道改为自动跟随方案最新版。
        DB::table('channels')
            ->whereNotNull('reception_plan_version_id')
            ->orderBy('id')
            ->each(function (object $channel): void {
                $planId = DB::table('reception_plan_versions')
                    ->where('id', $channel->reception_plan_version_id)
                    ->value('reception_plan_id');

                if ($planId !== null) {
                    DB::table('channels')
                        ->where('id', $channel->id)
                        ->update(['reception_plan_id' => $planId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex('idx_channels_workspace_plan');
            $table->dropColumn('reception_plan_id');
        });
    }
};
