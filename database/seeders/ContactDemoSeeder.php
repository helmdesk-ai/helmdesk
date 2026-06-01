<?php

namespace Database\Seeders;

use App\Models\Workspace;
use App\Services\DemoData\ContactDemoGenerator;
use Illuminate\Database\Seeder;

/**
 * 本地开发用：向第一个 workspace 造一套联系人 demo 数据。
 *
 *   php artisan db:seed --class=ContactDemoSeeder
 */
class ContactDemoSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::query()->first();

        if (! $workspace) {
            $this->command?->warn('No workspace found; create one first, then re-run this seeder.');

            return;
        }

        $count = app(ContactDemoGenerator::class)->generatePreset($workspace);

        $this->command?->info("Seeded {$count} contacts for workspace [{$workspace->slug}].");
    }
}
