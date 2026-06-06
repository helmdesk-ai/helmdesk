<?php

use App\Models\Conversation;
use Database\Seeders\ConversationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

test('ConversationDemoSeeder会为第一个系统填充会话', function () {
    $this->createUserWithSystem();

    $this->artisan('db:seed', ['--class' => ConversationDemoSeeder::class])
        ->assertSuccessful();

    expect(Conversation::query()->count())->toBeGreaterThan(0);
});
