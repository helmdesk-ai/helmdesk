<?php

namespace Database\Seeders;

use App\Models\SystemContext;
use App\Services\DemoData\ContactDemoGenerator;
use Illuminate\Database\Seeder;

/**
 * 本地开发用：向单租户系统造一套联系人 demo 数据。
 *
 *   php artisan db:seed --class=ContactDemoSeeder
 */
class ContactDemoSeeder extends Seeder
{
    public function run(): void
    {
        $systemContext = SystemContext::current();

        $count = app(ContactDemoGenerator::class)->generatePreset($systemContext);

        $this->command?->info("Seeded {$count} contacts.");
    }
}
