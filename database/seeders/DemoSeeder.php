<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 本地开发用：一键造齐所有 demo 数据（联系人 + 会话）。
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * 生产必需的 seed（例如内置 AI 厂商）在 {@see DatabaseSeeder}，两条线互不干扰。
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ContactDemoSeeder::class,
            ConversationDemoSeeder::class,
        ]);
    }
}
