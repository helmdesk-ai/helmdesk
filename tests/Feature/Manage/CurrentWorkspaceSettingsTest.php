<?php

use App\Enums\AttachmentPurpose;
use App\Models\Attachment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace([], [
        'name' => '旧工作区',
        'slug' => 'old-workspace',
    ]);
});

test('所有者可以更新当前工作区并显示成功提示', function () {
    $this->actingAs($this->user)
        ->put(route('workspace.manage.workspaces.current.update', ['slug' => $this->workspaceSlug()]), [
            'name' => '新工作区',
            'slug' => 'old-workspace',
            'logo_id' => null,
        ])
        ->assertRedirect(route('workspace.manage.workspaces.current.show', ['slug' => $this->workspaceSlug()]))
        ->assertSessionHasNoErrors();

    expect($this->workspace->refresh()->name)->toBe('新工作区');
});

test('所有者更新当前工作区时会绑定 Logo 附件', function () {
    $logo = Attachment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'purpose' => AttachmentPurpose::Avatar,
    ]);

    $this->actingAs($this->user)
        ->put(route('workspace.manage.workspaces.current.update', ['slug' => $this->workspaceSlug()]), [
            'name' => '新工作区',
            'slug' => 'old-workspace',
            'logo_id' => $logo->id,
        ])
        ->assertRedirect(route('workspace.manage.workspaces.current.show', ['slug' => $this->workspaceSlug()]))
        ->assertSessionHasNoErrors();

    expect($this->workspace->refresh()->logo_id)->toBe($logo->id)
        ->and($logo->fresh()->attachable_id)->toBe($this->workspace->id)
        ->and($logo->fresh()->attachable_type)->toBe($this->workspace->getMorphClass());
});

test('所有者可以创建工作区并显示成功提示', function () {
    $this->actingAs($this->user)
        ->post(route('workspace.manage.workspaces.store', ['slug' => $this->workspaceSlug()]), [
            'name' => '新建工作区',
            'slug' => 'created-workspace',
            'logo_id' => null,
        ])
        ->assertRedirect(route('workspace.manage.workspaces.current.show', ['slug' => 'created-workspace']))
        ->assertSessionHasNoErrors();

    expect(Workspace::query()->where('slug', 'created-workspace')->exists())->toBeTrue();
});

test('所有者创建工作区时会绑定 Logo 附件', function () {
    $logo = Attachment::factory()->create([
        'workspace_id' => null,
        'purpose' => AttachmentPurpose::Avatar,
    ]);

    $this->actingAs($this->user)
        ->post(route('workspace.manage.workspaces.store', ['slug' => $this->workspaceSlug()]), [
            'name' => '新建工作区',
            'slug' => 'created-workspace',
            'logo_id' => $logo->id,
        ])
        ->assertRedirect(route('workspace.manage.workspaces.current.show', ['slug' => 'created-workspace']))
        ->assertSessionHasNoErrors();

    $workspace = Workspace::query()->where('slug', 'created-workspace')->firstOrFail();

    expect($workspace->logo_id)->toBe($logo->id)
        ->and($logo->fresh()->attachable_id)->toBe($workspace->id)
        ->and($logo->fresh()->attachable_type)->toBe($workspace->getMorphClass());
});
