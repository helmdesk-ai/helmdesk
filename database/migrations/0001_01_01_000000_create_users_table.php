<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('avatar')->nullable();
            $table->string('locale', 20)->default('zh-CN');
            $table->string('timezone', 80)->nullable();
            $table->json('notification_preferences')->nullable();
            $table->string('role', 20)->default('owner')->comment('后台成员角色');
            $table->string('nickname', 50)->nullable()->comment('后台显示昵称');
            $table->unsignedTinyInteger('online_status')->default(1)->comment('在线状态');
            $table->timestamp('last_active_at')->nullable()->comment('最后活跃时间');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->text('two_factor_secret')->after('password')->nullable();
            $table->text('two_factor_recovery_codes')->after('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable();
            $table->boolean('is_super_admin')->default(false)->comment('是否为超级管理员');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
