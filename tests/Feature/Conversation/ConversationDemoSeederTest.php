<?php

use App\Models\Conversation;
use Database\Seeders\ConversationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

test('ConversationDemoSeeder会为第一个工作区填充会话', function () {
    $this->createUserWithWorkspace();

    $this->artisan('db:seed', ['--class' => ConversationDemoSeeder::class])
        ->assertSuccessful();

    expect(Conversation::query()->where('workspace_id', $this->workspace->id)->count())->toBeGreaterThan(0);
});
