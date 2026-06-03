<?php

use App\Actions\Inbox\UpdateConversationVisitorLocaleAction;
use App\Data\Inbox\FormUpdateConversationVisitorLocaleData;
use App\Enums\ReceptionLanguage;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function (): void {
    $this->user = $this->createUserWithSystem();
    $this->contact = Contact::factory()->create(['locale' => null]);
    $this->conversation = Conversation::factory()
        ->forContact($this->contact)
        ->assignedTo($this->user)
        ->create([
            'visitor_locale' => ReceptionLanguage::ChineseSimplified->value,
        ]);
});

test('更新会话访客语言', function (): void {
    $conversation = UpdateConversationVisitorLocaleAction::run($this->conversation->id, ReceptionLanguage::Japanese);

    expect($conversation->visitor_locale)->toBe('ja')
        ->and($this->conversation->fresh()->visitor_locale)->toBe('ja');
});

test('控制器更新访客语言并返回收件箱页面', function (): void {
    $this->actingAs($this->user)
        ->from(route('admin.inbox.show', ['conversation' => $this->conversation->id,
        ]))
        ->put(route('admin.inbox.conversations.visitor-locale.update', ['conversation' => $this->conversation->id,
        ]), [
            'visitor_locale' => 'en',
        ])
        ->assertRedirect(route('admin.inbox.show', ['conversation' => $this->conversation->id,
        ]));

    expect($this->conversation->fresh()->visitor_locale)->toBe('en');
});

test('单租户后台可以更新任意会话访客语言', function (): void {
    $otherConversation = Conversation::factory()->create();

    $conversation = UpdateConversationVisitorLocaleAction::run(
        $otherConversation->id,
        ReceptionLanguage::English,
    );

    expect($conversation->visitor_locale)->toBe('en');
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
