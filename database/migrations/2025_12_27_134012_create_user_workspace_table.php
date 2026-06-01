<?php

use App\Enums\UserOnlineStatus;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_workspace', function (Blueprint $table) {
            $table->timestamps();
            $table->ulid('workspace_id');
            $table->ulid('user_id');
            $table->string('role')->default(WorkspaceRole::Operator->value)->comment('角色');
            $table->integer('online_status')->default(UserOnlineStatus::Online->value);
            $table->string('nickname', 50)->nullable();
            $table->timestamp('last_active_at')->nullable()->comment('最后活跃时间');
            $table->primary(['workspace_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_workspace');
    }
};
