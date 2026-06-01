<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace();
});

it('renders the preview iframe shell for authenticated users', function () {
    $this->withoutVite();

    $this->actingAs($this->user)
        ->get(route('channels.web.preview'))
        ->assertOk()
        ->assertViewIs('channel-preview');
});

it('requires authentication', function () {
    $this->get(route('channels.web.preview'))
        ->assertRedirect();
});
