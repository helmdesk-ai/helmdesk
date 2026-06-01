<?php

use App\Actions\Inbox\UpdateConversationVisitorLocaleAction;
use App\Data\Inbox\FormUpdateConversationVisitorLocaleData;
use App\Enums\ReceptionLanguage;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\WithWorkspace;

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function (): void {
    $this->user = $this->createUserWithWorkspace();
    $this->contact = Contact::factory()->for($this->workspace)->create(['locale' => null]);
    $this->conversation = Conversation::factory()
        ->forContact($this->contact)
        ->assignedTo($this->user)
        ->create([
            'workspace_id' => $this->workspace->id,
            'visitor_locale' => ReceptionLanguage::ChineseSimplified->value,
        ]);
});

test('更新会话访客语言', function (): void {
    $conversation = UpdateConversationVisitorLocaleAction::run($this->workspace, $this->conversation->id, ReceptionLanguage::Japanese);

    expect($conversation->visitor_locale)->toBe('ja')
        ->and($this->conversation->fresh()->visitor_locale)->toBe('ja');
});

test('控制器更新访客语言并返回收件箱页面', function (): void {
    $this->actingAs($this->user)
        ->from(route('workspace.inbox.show', [
            'slug' => $this->workspaceSlug(),
            'conversation' => $this->conversation->id,
        ]))
        ->put(route('workspace.inbox.conversations.visitor-locale.update', [
            'slug' => $this->workspaceSlug(),
            'conversation' => $this->conversation->id,
        ]), [
            'visitor_locale' => 'en',
        ])
        ->assertRedirect(route('workspace.inbox.show', [
            'slug' => $this->workspaceSlug(),
            'conversation' => $this->conversation->id,
        ]));

    expect($this->conversation->fresh()->visitor_locale)->toBe('en');
});

test('不能更新其他工作区的会话语言', function (): void {
    [$otherWorkspace] = createWorkspaceWithOwner();
    $otherConversation = Conversation::factory()->for($otherWorkspace)->create();

    expect(fn () => UpdateConversationVisitorLocaleAction::run(
        $this->workspace,
        $otherConversation->id,
        ReceptionLanguage::English,
    ))->toThrow(NotFoundHttpException::class);
});

test('访客语言必须在接待语言选项内', function (): void {
    expect(fn () => FormUpdateConversationVisitorLocaleData::validateAndCreate([
        'visitor_locale' => 'hi',
    ]))->toThrow(ValidationException::class);
});

test('访客语言必须是 locale code', function (): void {
    expect(fn () => FormUpdateConversationVisitorLocaleData::validateAndCreate([
        'visitor_locale' => 'English',
    ]))->toThrow(ValidationException::class);
});
