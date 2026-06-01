<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('确认密码页面可以渲染', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertStatus(200);

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/ConfirmPassword')
    );
});

test('密码确认需要认证', function () {
    $response = $this->get(route('password.confirm'));

    $response->assertRedirect(route('login'));
});
