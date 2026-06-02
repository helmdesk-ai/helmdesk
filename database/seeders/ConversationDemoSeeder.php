<?php

namespace Database\Seeders;

use App\Models\Workspace;
use App\Services\DemoData\ConversationDemoGenerator;
use Illuminate\Database\Seeder;

/**
 * 本地开发用：向单租户系统造一批会话 demo 数据（含标签 & 自定义属性）。
 *
 *   php artisan db:seed --class=ConversationDemoSeeder
 */
class ConversationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::current();

        $result = app(ConversationDemoGenerator::class)->generate(
            $workspace,
            count: 30,
        );

        $this->command?->info(
            "Seeded {$result['conversations']} conversations / {$result['messages']} messages."
        );
    }
}
