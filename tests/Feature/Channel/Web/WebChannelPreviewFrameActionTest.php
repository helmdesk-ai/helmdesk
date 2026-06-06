<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();
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
