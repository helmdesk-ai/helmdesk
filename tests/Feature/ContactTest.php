<?php

use App\Actions\Contact\CreateContactAction;
use App\Actions\Contact\CreateContactIdentityAction;
use App\Actions\Contact\DeleteContactAction;
use App\Actions\Contact\DeleteContactIdentityAction;
use App\Actions\Contact\GetContactTrashListAction;
use App\Actions\Contact\MergeContactsAction;
use App\Actions\Contact\ReplaceContactIdentityAction;
use App\Actions\Contact\ResolveContactIdentityAction;
use App\Actions\Contact\RestoreContactAction;
use App\Actions\Contact\ShowContactListAction;
use App\Actions\Contact\UpdateContactAction;
use App\Actions\Contact\UpdateContactImportanceAction;
use App\Data\Contact\FormCreateContactData;
use App\Data\Contact\FormCreateContactIdentityData;
use App\Data\Contact\FormReplaceContactIdentityData;
use App\Data\Contact\FormUpdateContactData;
use App\Data\Contact\FormUpdateContactImportanceData;
use App\Enums\ContactListType;
use App\Enums\ContactSource;
use App\Enums\ContactType;
use App\Enums\IdentityType;
use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\ContactIdentity;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contact\ContactAiContext;
use Database\Seeders\ContactDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// === Contact List ===

test('联系人列表页面需要认证', function () {
    $workspace = Workspace::factory()->create();

    $this->get(route('workspace.contacts.index', ['slug' => $workspace->slug, 'type' => 'all']))
        ->assertRedirect(route('login'));
});

test('联系人列表页面渲染并包含数据', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    Contact::factory()
        ->count(3)
        ->for($workspace)
        ->has(ContactIdentity::factory()->email(), 'identities')
        ->create();

    $props = ShowContactListAction::run(
        workspace: $workspace,
        type: ContactListType::All,
    );

    expect($props->contact_list)->toHaveCount(3)
        ->and($props->contact_list_pagination)->not->toBeNull()
        ->and($props->current_type)->toBe(ContactListType::All);
});

test('联系人列表拒绝无效列表类型', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $this->actingAs($user)
        ->get("/w/{$workspace->slug}/contacts/unknown/index")
        ->assertNotFound();
});

test('联系人列表筛选按类型', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    Contact::factory()->count(2)->visitor()->for($workspace)->create();
    Contact::factory()->count(3)->contact()->for($workspace)->create();

    $visitorProps = ShowContactListAction::run(
        workspace: $workspace,
        type: ContactListType::Visitors,
    );

    expect($visitorProps->contact_list)->toHaveCount(2)
        ->and($visitorProps->current_type)->toBe(ContactListType::Visitors);

    $contactProps = ShowContactListAction::run(
        workspace: $workspace,
        type: ContactListType::Contacts,
    );

    expect($contactProps->contact_list)->toHaveCount(3)
        ->and($contactProps->current_type)->toBe(ContactListType::Contacts);
});

test('联系人列表支持重点客户筛选并优先显示重点客户', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $important = Contact::factory()->for($workspace)->create([
        'name' => '重点客户',
        'is_important' => true,
        'important_at' => now()->subDay(),
        'important_source' => 'manual',
        'last_seen_at' => now()->subDays(5),
    ]);
    $normal = Contact::factory()->for($workspace)->create([
        'name' => '普通客户',
        'last_seen_at' => now(),
    ]);

    $allProps = ShowContactListAction::run(
        workspace: $workspace,
        type: ContactListType::All,
    );

    expect($allProps->contact_list[0]->id)->toBe($important->id)
        ->and($allProps->contact_list[1]->id)->toBe($normal->id);

    $importantProps = ShowContactListAction::run(
        workspace: $workspace,
        type: ContactListType::All,
        importantOnly: true,
    );

    expect($importantProps->important_only)->toBeTrue()
        ->and($importantProps->contact_list)->toHaveCount(1)
        ->and($importantProps->contact_list[0]->id)->toBe($important->id)
        ->and($importantProps->contact_list[0]->is_important)->toBeTrue();
});

test('联系人列表支持分页参数', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    Contact::factory()
        ->count(12)
        ->for($workspace)
        ->has(ContactIdentity::factory()->email(), 'identities')
        ->create();

    $props = ShowContactListAction::run(
        workspace: $workspace,
        type: ContactListType::All,
        page: 2,
        perPage: 15,
    );

    expect($props->contact_list_pagination->current_page)->toBe(2)
        ->and($props->contact_list_pagination->per_page)->toBe(15)
        ->and($props->contact_list_pagination->total)->toBe(12);
});

test('联系人回收站页面渲染软删除联系人', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $trashedContact = Contact::factory()
        ->for($workspace)
        ->create([
            'name' => '已删除联系人',
        ]);

    DeleteContactAction::run($workspace, $trashedContact->id);

    Contact::factory()
        ->for($workspace)
        ->create([
            'name' => '活跃联系人',
        ]);

    $props = GetContactTrashListAction::run(workspace: $workspace);

    expect($props->contact_trash_list)->toHaveCount(1)
        ->and($props->contact_trash_list[0]->name)->toBe('已删除联系人')
        ->and($props->contact_trash_list_pagination->total)->toBe(1);
});

test('联系人搜索不会模糊匹配不同邮箱词元', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $scoutStorage = storage_path('framework/testing/scout-'.Str::random(8));
    File::ensureDirectoryExists($scoutStorage);

    config()->set('scout.driver', 'tntsearch');
    config()->set('scout.tntsearch.storage', $scoutStorage);
    config()->set('scout.tntsearch.fuzziness', false);

    $contact = CreateContactAction::run($workspace, new FormCreateContactData(
        email: 't1@test.com',
    ));

    $matchedIds = Contact::search('test2')
        ->where('workspace_id', $workspace->id)
        ->keys();

    expect($matchedIds)->not->toContain($contact->id);

    File::deleteDirectory($scoutStorage);
});

// === Create Contact ===

test('可以创建联系人并带邮箱', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $data = new FormCreateContactData(
        name: '张三',
        email: 'zhang@example.com',
        phone: null,
    );

    $contact = CreateContactAction::run($workspace, $data);

    expect($contact)->toBeInstanceOf(Contact::class)
        ->and($contact->name)->toBe('张三')
        ->and($contact->type)->toBe(ContactType::Contact)
        ->and($contact->source)->toBe(ContactSource::Manual)
        ->and($contact->avatar_url)->toBe(Contact::DEFAULT_AVATAR_URL)
        ->and($contact->primary_email)->toBe('zhang@example.com');

    expect($contact->identities)->toHaveCount(1);
    expect($contact->identities->first()->type)->toBe(IdentityType::Email);
    expect($contact->activityLogs()->latest('created_at')->value('action'))->toBe('created');
});

test('可以创建联系人并带电话', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $data = new FormCreateContactData(
        name: null,
        email: null,
        phone: '+8613800138000',
    );

    $contact = CreateContactAction::run($workspace, $data);

    expect($contact->primary_phone)->toBe('+8613800138000')
        ->and($contact->name)->toBeNull()
        ->and($contact->type)->toBe(ContactType::Contact);
});

test('创建联系人会拒绝无效身份载荷', function (string $case) {
    [$workspace, $user] = createWorkspaceWithOwner();

    $data = match ($case) {
        'missing identity' => new FormCreateContactData(name: '张三'),
        'duplicate email' => tap(new FormCreateContactData(email: 'dup@example.com'), function () use ($workspace): void {
            $existing = Contact::factory()->for($workspace)->create();
            ContactIdentity::factory()->email()->create([
                'contact_id' => $existing->id,
                'workspace_id' => $workspace->id,
                'value' => 'dup@example.com',
            ]);
        }),
        'duplicate phone' => tap(new FormCreateContactData(phone: '+86 13800138000'), function () use ($workspace): void {
            $existing = Contact::factory()->for($workspace)->create();
            ContactIdentity::factory()->phone()->create([
                'contact_id' => $existing->id,
                'workspace_id' => $workspace->id,
                'value' => '+8613800138000',
                'display_value' => '+8613800138000',
            ]);
        }),
        'invalid phone' => new FormCreateContactData(phone: 'call-me-maybe'),
        'phone without country code' => new FormCreateContactData(phone: '18995543120'),
    };

    expect(fn () => CreateContactAction::run($workspace, $data))
        ->toThrow(ValidationException::class);
})->with([
    'missing identity',
    'duplicate email',
    'duplicate phone',
    'invalid phone',
    'phone without country code',
]);

test('创建联系人将邮箱标准化为小写', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $data = new FormCreateContactData(email: 'Zhang@Example.COM');
    $contact = CreateContactAction::run($workspace, $data);

    expect($contact->identities->first()->value)->toBe('zhang@example.com');
});

test('创建联系人将电话号码标准化为E.164', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $data = new FormCreateContactData(phone: '+86 138 0013 8000');
    $contact = CreateContactAction::run($workspace, $data);

    expect($contact->primary_phone)->toBe('+8613800138000')
        ->and($contact->identities->first()->value)->toBe('+8613800138000');
});

test('解析并创建联系人并带默认头像URL', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = ResolveContactIdentityAction::run($workspace, [
        'type' => IdentityType::Session,
        'value' => 'sess-default-avatar',
    ]);

    expect($contact->avatar_url)->toBe(Contact::DEFAULT_AVATAR_URL);
});

// === Update Contact ===

test('可以更新联系人名称和类型', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->visitor()->for($workspace)->create(['name' => '旧名字']);

    $data = new FormUpdateContactData(
        name: '新名字',
        type: 'contact',
        note: '长期备注',
        country: '中国',
        city: '上海',
    );
    $updated = UpdateContactAction::run($workspace, $contact->id, $data);

    expect($updated->name)->toBe('新名字')
        ->and($updated->type)->toBe(ContactType::Contact)
        ->and($updated->note)->toBe('长期备注')
        ->and($updated->country)->toBe('中国')
        ->and($updated->city)->toBe('上海');
    expect($contact->fresh()->activityLogs()->latest('created_at')->value('action'))->toBe('updated');
    expect(data_get($contact->fresh()->activityLogs()->latest('created_at')->first()?->payload, 'field_changes.type.new'))
        ->toBe('contact');
});

test('可以标记和取消标记重点客户', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create([
        'is_important' => false,
    ]);

    $this->actingAs($user)
        ->putJson(route('workspace.contacts.importance.update', [
            'slug' => $workspace->slug,
            'id' => $contact->id,
        ]), [
            'is_important' => true,
        ])
        ->assertOk()
        ->assertJsonPath('is_important', true);

    $contact->refresh();
    expect($contact->is_important)->toBeTrue()
        ->and($contact->important_at)->not->toBeNull()
        ->and($contact->important_by_user_id)->toBe($user->id)
        ->and($contact->important_source)->toBe('manual')
        ->and($contact->activityLogs()->latest('created_at')->value('action'))->toBe(ContactActivityLog::ACTION_IMPORTANT_MARKED);

    $this->actingAs($user)
        ->putJson(route('workspace.contacts.importance.update', [
            'slug' => $workspace->slug,
            'id' => $contact->id,
        ]), [
            'is_important' => false,
        ])
        ->assertOk()
        ->assertJsonPath('is_important', false)
        ->assertJsonPath('important_at', null);

    $contact->refresh();
    expect($contact->is_important)->toBeFalse()
        ->and($contact->important_at)->toBeNull()
        ->and($contact->important_by_user_id)->toBeNull()
        ->and($contact->important_source)->toBeNull()
        ->and($contact->activityLogs()->latest('created_at')->value('action'))->toBe(ContactActivityLog::ACTION_IMPORTANT_UNMARKED);
});

test('重复提交相同重点客户状态不重复写活动日志', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create([
        'is_important' => true,
        'important_at' => now(),
        'important_by_user_id' => $user->id,
        'important_source' => 'manual',
    ]);

    UpdateContactImportanceAction::run(
        $workspace,
        $contact->id,
        new FormUpdateContactImportanceData(is_important: true),
        $user,
    );

    expect($contact->activityLogs()->count())->toBe(0);
});

// === Delete Contact ===

test('删除联系人软删除联系人和身份', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $identity = ContactIdentity::factory()->email()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
    ]);

    DeleteContactAction::run($workspace, $contact->id);

    expect(Contact::find($contact->id))->toBeNull();
    expect(Contact::withTrashed()->find($contact->id))->not->toBeNull();
    expect(ContactIdentity::find($identity->id))->toBeNull();
    expect(ContactIdentity::withTrashed()->find($identity->id))->not->toBeNull();
    expect(Contact::withTrashed()->findOrFail($contact->id)->activityLogs()->latest('created_at')->value('action'))
        ->toBe('deleted');
});

test('软删除身份会为新联系人释放唯一键', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact1 = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->email()->create([
        'contact_id' => $contact1->id,
        'workspace_id' => $workspace->id,
        'value' => 'release@example.com',
    ]);

    DeleteContactAction::run($workspace, $contact1->id);

    $data = new FormCreateContactData(email: 'release@example.com');
    $contact2 = CreateContactAction::run($workspace, $data);

    expect($contact2->primary_email)->toBe('release@example.com')
        ->and($contact2->id)->not->toBe($contact1->id);
});

// === Restore Contact ===

test('可以恢复软已删除联系人并带身份', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->email()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
        'value' => 'restore@example.com',
    ]);

    DeleteContactAction::run($workspace, $contact->id);
    $restored = RestoreContactAction::run($workspace, $contact->id);

    expect($restored->trashed())->toBeFalse();
    expect($restored->identities()->count())->toBe(1);
    expect($restored->activityLogs()->latest('created_at')->value('action'))
        ->toBe('restored');
});

test('恢复联系人拒绝当身份冲突并带活跃记录', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact1 = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->email()->create([
        'contact_id' => $contact1->id,
        'workspace_id' => $workspace->id,
        'value' => 'conflict@example.com',
    ]);

    DeleteContactAction::run($workspace, $contact1->id);

    $contact2 = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->email()->create([
        'contact_id' => $contact2->id,
        'workspace_id' => $workspace->id,
        'value' => 'conflict@example.com',
    ]);

    RestoreContactAction::run($workspace, $contact1->id);
})->throws(ValidationException::class);

// === Create Contact Identity ===

test('可以添加身份到现有联系人', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $data = new FormCreateContactIdentityData(type: 'email', value: 'new@example.com');

    $identity = CreateContactIdentityAction::run($workspace, $contact->id, $data);

    expect($identity->type)->toBe(IdentityType::Email)
        ->and($identity->value)->toBe('new@example.com');

    $contact->refresh();
    expect($contact->primary_email)->toBe('new@example.com');
    expect($contact->activityLogs()->latest('created_at')->value('action'))->toBe('identity_added');
});

test('可以添加电话身份并带标准化值', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $data = new FormCreateContactIdentityData(type: 'phone', value: '+86 13800138000');

    $identity = CreateContactIdentityAction::run($workspace, $contact->id, $data);

    expect($identity->value)->toBe('+8613800138000')
        ->and($identity->display_value)->toBe('+8613800138000');
});

test('添加身份检测到重复', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact1 = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->email()->create([
        'contact_id' => $contact1->id,
        'workspace_id' => $workspace->id,
        'value' => 'taken@example.com',
    ]);

    $contact2 = Contact::factory()->for($workspace)->create();
    $data = new FormCreateContactIdentityData(type: 'email', value: 'taken@example.com');

    CreateContactIdentityAction::run($workspace, $contact2->id, $data);
})->throws(ValidationException::class);

test('添加电话身份拒绝无效电话输入', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $data = new FormCreateContactIdentityData(type: 'phone', value: 'invalid-phone');

    CreateContactIdentityAction::run($workspace, $contact->id, $data);
})->throws(ValidationException::class);

test('添加电话身份拒绝电话且没有国家代码', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $data = new FormCreateContactIdentityData(type: 'phone', value: '18995543120');

    CreateContactIdentityAction::run($workspace, $contact->id, $data);
})->throws(ValidationException::class);

test('external_id需要命名空间', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $data = new FormCreateContactIdentityData(type: 'external_id', value: '12345');

    CreateContactIdentityAction::run($workspace, $contact->id, $data);
})->throws(ValidationException::class);

test('带命名空间的external_id可以工作', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $data = new FormCreateContactIdentityData(type: 'external_id', value: '12345', namespace: 'api:default');

    $identity = CreateContactIdentityAction::run($workspace, $contact->id, $data);

    expect($identity->type)->toBe(IdentityType::ExternalId)
        ->and($identity->namespace)->toBe('api:default');
});

test('添加邮箱身份将访客提升为联系人', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->visitor()->for($workspace)->create();
    expect($contact->type)->toBe(ContactType::Visitor);

    $data = new FormCreateContactIdentityData(type: 'email', value: 'upgrade@example.com');
    CreateContactIdentityAction::run($workspace, $contact->id, $data);

    $contact->refresh();
    expect($contact->type)->toBe(ContactType::Contact);
});

test('添加邮箱身份不会降级联系人类型', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->contact()->for($workspace)->create();

    $data = new FormCreateContactIdentityData(type: 'email', value: 'stay@example.com');
    CreateContactIdentityAction::run($workspace, $contact->id, $data);

    $contact->refresh();
    expect($contact->type)->toBe(ContactType::Contact);
});

test('可以删除邮箱身份和同步主字段', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $primaryIdentity = ContactIdentity::factory()->email()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
        'value' => 'first@example.com',
        'display_value' => 'first@example.com',
        'created_at' => now()->subMinute(),
    ]);
    ContactIdentity::factory()->email()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
        'value' => 'second@example.com',
        'display_value' => 'second@example.com',
    ]);

    $contact->syncPrimaryFields();

    DeleteContactIdentityAction::run($workspace, $contact->id, $primaryIdentity->id);

    expect(ContactIdentity::find($primaryIdentity->id))->toBeNull()
        ->and($contact->fresh()->primary_email)->toBe('second@example.com');
    expect($contact->fresh()->activityLogs()->latest('created_at')->value('action'))->toBe('identity_deleted');
});

test('替换身份会软删除旧身份并保留主身份排序', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $identity = ContactIdentity::factory()->phone()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
        'value' => '+8613800138000',
        'display_value' => '+8613800138000',
        'created_at' => now()->subHour(),
    ]);

    $replacement = ReplaceContactIdentityAction::run(
        $workspace,
        $contact->id,
        $identity->id,
        new FormReplaceContactIdentityData(value: '+852 5123 4567'),
    );

    expect(ContactIdentity::find($identity->id))->toBeNull();
    expect(ContactIdentity::withTrashed()->find($identity->id))->not->toBeNull();
    expect($replacement->value)->toBe('+85251234567')
        ->and($replacement->created_at?->toIso8601String())->toBe($identity->created_at?->toIso8601String())
        ->and($contact->fresh()->primary_phone)->toBe('+85251234567');
    expect($contact->fresh()->activityLogs()->latest('created_at')->value('action'))->toBe('identity_replaced');
    expect(data_get($contact->fresh()->activityLogs()->latest('created_at')->first()?->payload, 'old_value'))
        ->toBe('+8613800138000');
});

test('不能手动删除外部身份', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $identity = ContactIdentity::factory()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
        'type' => IdentityType::ExternalId,
        'namespace' => 'api:crm',
        'value' => 'crm-123',
        'display_value' => 'crm-123',
    ]);

    DeleteContactIdentityAction::run($workspace, $contact->id, $identity->id);
})->throws(ValidationException::class);

// === Resolve Contact Identity ===

test('解析并创建新联系人当没有匹配', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = ResolveContactIdentityAction::run($workspace, [
        'type' => IdentityType::Email,
        'value' => 'new@example.com',
    ]);

    expect($contact)->toBeInstanceOf(Contact::class)
        ->and($contact->identities)->toHaveCount(1)
        ->and($contact->primary_email)->toBe('new@example.com');
    expect($contact->activityLogs()->latest('created_at')->value('action'))->toBe('created');
});

test('解析并返回现有联系人当身份匹配', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $existing = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->email()->create([
        'contact_id' => $existing->id,
        'workspace_id' => $workspace->id,
        'value' => 'existing@example.com',
    ]);

    $resolved = ResolveContactIdentityAction::run($workspace, [
        'type' => IdentityType::Email,
        'value' => 'existing@example.com',
    ]);

    expect($resolved->id)->toBe($existing->id);
    expect(ContactIdentity::where('workspace_id', $workspace->id)->where('value', 'existing@example.com')->count())->toBe(1);
});

test('解析并匹配现有联系人当电话标准化到同一值', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $existing = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->phone()->create([
        'contact_id' => $existing->id,
        'workspace_id' => $workspace->id,
        'value' => '+8613800138000',
        'display_value' => '+8613800138000',
    ]);

    $resolved = ResolveContactIdentityAction::run($workspace, [
        'type' => IdentityType::Phone,
        'value' => '+86 13800138000',
    ]);

    expect($resolved->id)->toBe($existing->id);
    expect(ContactIdentity::where('workspace_id', $workspace->id)->where('value', '+8613800138000')->count())->toBe(1);
});

test('解析拒绝没有命名空间的external_id', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    ResolveContactIdentityAction::run($workspace, [
        'type' => IdentityType::ExternalId,
        'value' => 'ext-123',
    ]);
})->throws(ValidationException::class);

test('解析会创建联系人类型用于邮箱', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = ResolveContactIdentityAction::run($workspace, [
        'type' => IdentityType::Email,
        'value' => 'known@example.com',
    ]);

    expect($contact->type)->toBe(ContactType::Contact);
});

test('解析会创建访客类型用于会话', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = ResolveContactIdentityAction::run($workspace, [
        'type' => IdentityType::Session,
        'value' => 'sess-abc-123-def',
    ]);

    expect($contact->type)->toBe(ContactType::Visitor);
});

test('解析会将访客提升为联系人当匹配按邮箱', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $visitor = Contact::factory()->visitor()->for($workspace)->create();
    ContactIdentity::factory()->email()->create([
        'contact_id' => $visitor->id,
        'workspace_id' => $workspace->id,
        'value' => 'promote@example.com',
    ]);

    expect($visitor->type)->toBe(ContactType::Visitor);

    $resolved = ResolveContactIdentityAction::run($workspace, [
        'type' => IdentityType::Email,
        'value' => 'promote@example.com',
    ]);

    expect($resolved->id)->toBe($visitor->id)
        ->and($resolved->type)->toBe(ContactType::Contact);
    expect($visitor->fresh()->activityLogs()->latest('created_at')->value('action'))->toBe('updated');
});

// === Merge Contacts ===

test('合并拒绝联系人与自身合并', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->email()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
    ]);

    MergeContactsAction::run($workspace, $contact->id, $contact->id);
})->throws(InvalidArgumentException::class);

test('合并转移身份和删除已合并联系人', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $target = Contact::factory()->for($workspace)->create(['name' => '目标']);
    ContactIdentity::factory()->email()->create([
        'contact_id' => $target->id,
        'workspace_id' => $workspace->id,
        'value' => 'target@example.com',
    ]);

    $merged = Contact::factory()->for($workspace)->create(['name' => '被合并']);
    ContactIdentity::factory()->phone()->create([
        'contact_id' => $merged->id,
        'workspace_id' => $workspace->id,
        'value' => '+8613800138000',
    ]);

    $result = MergeContactsAction::run($workspace, $target->id, $merged->id);

    expect($result->id)->toBe($target->id)
        ->and($result->name)->toBe('目标');

    expect(Contact::find($merged->id))->toBeNull();

    $log = ContactActivityLog::query()
        ->where('contact_id', $target->id)
        ->where('action', ContactActivityLog::ACTION_MERGED_INTO_CURRENT)
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->related_contact_id)->toBe($merged->id)
        ->and(data_get($log->payload, 'identity_snapshots.0.value'))->toBe('+8613800138000')
        ->and(data_get($log->payload, 'merged_attributes.name'))->toBe('被合并');
});

test('合并填充null属性来自已合并联系人', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $target = Contact::factory()->for($workspace)->create([
        'name' => '目标',
        'locale' => null,
        'timezone' => null,
    ]);
    ContactIdentity::factory()->email()->create([
        'contact_id' => $target->id,
        'workspace_id' => $workspace->id,
    ]);

    $merged = Contact::factory()->for($workspace)->create([
        'name' => '被合并',
        'locale' => 'zh_CN',
        'timezone' => 'Asia/Shanghai',
    ]);
    ContactIdentity::factory()->phone()->create([
        'contact_id' => $merged->id,
        'workspace_id' => $workspace->id,
    ]);

    $result = MergeContactsAction::run($workspace, $target->id, $merged->id);

    expect($result->name)->toBe('目标')
        ->and($result->locale)->toBe('zh_CN')
        ->and($result->timezone)->toBe('Asia/Shanghai');
});

test('合并提升目标到联系人类型当已合并是联系人类型', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $target = Contact::factory()->visitor()->for($workspace)->create();
    ContactIdentity::factory()->session()->create([
        'contact_id' => $target->id,
        'workspace_id' => $workspace->id,
    ]);

    $merged = Contact::factory()->contact()->for($workspace)->create();
    ContactIdentity::factory()->email()->create([
        'contact_id' => $merged->id,
        'workspace_id' => $workspace->id,
        'value' => 'merge@example.com',
        'display_value' => 'merge@example.com',
    ]);

    $result = MergeContactsAction::run($workspace, $target->id, $merged->id);

    expect($result->type)->toBe(ContactType::Contact);
});

test('合并保留较晚last_seen_at', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $target = Contact::factory()->for($workspace)->create([
        'last_seen_at' => now()->subDays(2),
    ]);
    ContactIdentity::factory()->email()->create([
        'contact_id' => $target->id,
        'workspace_id' => $workspace->id,
    ]);

    $later = now()->subHour();
    $merged = Contact::factory()->for($workspace)->create([
        'last_seen_at' => $later,
    ]);
    ContactIdentity::factory()->phone()->create([
        'contact_id' => $merged->id,
        'workspace_id' => $workspace->id,
    ]);

    $result = MergeContactsAction::run($workspace, $target->id, $merged->id);

    expect($result->last_seen_at->toDateTimeString())->toBe($later->toDateTimeString());
});

test('合并会保留重点客户标记', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $target = Contact::factory()->for($workspace)->create([
        'is_important' => false,
    ]);
    ContactIdentity::factory()->email()->create([
        'contact_id' => $target->id,
        'workspace_id' => $workspace->id,
    ]);

    $markedAt = now()->subHours(2);
    $merged = Contact::factory()->for($workspace)->create([
        'is_important' => true,
        'important_at' => $markedAt,
        'important_by_user_id' => $user->id,
        'important_source' => 'manual',
    ]);
    ContactIdentity::factory()->phone()->create([
        'contact_id' => $merged->id,
        'workspace_id' => $workspace->id,
    ]);

    $result = MergeContactsAction::run($workspace, $target->id, $merged->id);

    expect($result->is_important)->toBeTrue()
        ->and($result->important_at?->toDateTimeString())->toBe($markedAt->toDateTimeString())
        ->and($result->important_by_user_id)->toBe($user->id)
        ->and($result->important_source)->toBe('manual');
});

test('合并会保留目标ai_context值、填充缺失键并刷新更新时间戳', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $target = Contact::factory()->for($workspace)->create([
        'ai_context' => [
            'preferences' => '偏好中文',
            '_updated_at' => '2026-01-01T00:00:00+00:00',
        ],
    ]);
    ContactIdentity::factory()->email()->create([
        'contact_id' => $target->id,
        'workspace_id' => $workspace->id,
    ]);

    $merged = Contact::factory()->for($workspace)->create([
        'ai_context' => [
            'preferences' => 'prefers English',
            'sentiment' => 'positive',
            '_updated_at' => '2026-01-02T00:00:00+00:00',
        ],
    ]);
    ContactIdentity::factory()->phone()->create([
        'contact_id' => $merged->id,
        'workspace_id' => $workspace->id,
    ]);

    $beforeMerge = now()->subSecond();

    $result = MergeContactsAction::run($workspace, $target->id, $merged->id);

    expect($result->ai_context)->toBeArray()
        ->and($result->ai_context['preferences'])->toBe('偏好中文')
        ->and($result->ai_context['sentiment'])->toBe('positive')
        ->and($result->ai_context['_updated_at'])->not->toBe('2026-01-01T00:00:00+00:00')
        ->and($result->ai_context['_updated_at'])->not->toBe('2026-01-02T00:00:00+00:00')
        ->and($result->ai_context['_updated_at'])->toBeString();

    expect($result->fresh()->ai_context['_updated_at'])->toBeString();
    expect(strtotime($result->fresh()->ai_context['_updated_at']))->toBeGreaterThanOrEqual($beforeMerge->timestamp);
});

test('合并拒绝过大的ai_context载荷', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $target = Contact::factory()->for($workspace)->create([
        'ai_context' => [
            'summary' => str_repeat('a', ContactAiContext::MAX_BYTES),
        ],
    ]);
    ContactIdentity::factory()->email()->create([
        'contact_id' => $target->id,
        'workspace_id' => $workspace->id,
    ]);

    $merged = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->phone()->create([
        'contact_id' => $merged->id,
        'workspace_id' => $workspace->id,
    ]);

    MergeContactsAction::run($workspace, $target->id, $merged->id);
})->throws(ValidationException::class);

// === Cross-workspace isolation ===

test('联系人在之间隔离工作区', function () {
    $user = User::factory()->create();
    $ws1 = Workspace::factory()->create(['owner_id' => $user->id]);
    $ws2 = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->workspaces()->attach($ws1, ['role' => 'owner']);
    $user->workspaces()->attach($ws2, ['role' => 'owner']);

    Contact::factory()->count(3)->for($ws1)->create();
    Contact::factory()->count(5)->for($ws2)->create();

    $props1 = ShowContactListAction::run(workspace: $ws1, type: ContactListType::All);
    expect($props1->contact_list)->toHaveCount(3);

    $props2 = ShowContactListAction::run(workspace: $ws2, type: ContactListType::All);
    expect($props2->contact_list)->toHaveCount(5);
});

// === HTTP endpoints ===

test('可以创建联系人通过HTTP', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $this->actingAs($user)
        ->post(route('workspace.contacts.store', ['slug' => $workspace->slug]), [
            'email' => 'http@example.com',
        ])
        ->assertRedirect();

    expect(Contact::where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('可以获取联系人详情通过HTTP', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->email()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user)
        ->getJson(route('workspace.contacts.show', ['slug' => $workspace->slug, 'id' => $contact->id]))
        ->assertOk()
        ->assertJsonStructure(['id', 'name', 'type', 'source', 'identities']);
});

test('联系人详情包含命名空间用于外部身份', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    ContactIdentity::factory()->externalId('ext-123', 'api:shopify')->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user)
        ->getJson(route('workspace.contacts.show', ['slug' => $workspace->slug, 'id' => $contact->id]))
        ->assertOk()
        ->assertJsonPath('identities.0.namespace', 'api:shopify');
});

test('请求包含已删除数据时可以通过HTTP获取已删除联系人详情', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $identity = ContactIdentity::factory()->email()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
        'value' => 'trashed@example.com',
        'display_value' => 'trashed@example.com',
    ]);

    DeleteContactAction::run($workspace, $contact->id);

    $this->actingAs($user)
        ->getJson(route('workspace.contacts.show', [
            'slug' => $workspace->slug,
            'id' => $contact->id,
            'include_trashed' => 1,
        ]))
        ->assertOk()
        ->assertJsonPath('deleted_at', Contact::withTrashed()->findOrFail($contact->id)->deleted_at?->toIso8601String())
        ->assertJsonPath('identities.0.display_value', $identity->display_value);
});

test('联系人详情包含合并日志用于活跃和已合并联系人', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $target = Contact::factory()->for($workspace)->create(['name' => '目标联系人']);
    ContactIdentity::factory()->email()->create([
        'contact_id' => $target->id,
        'workspace_id' => $workspace->id,
        'value' => 'target@example.com',
        'display_value' => 'target@example.com',
    ]);

    $merged = Contact::factory()->for($workspace)->create(['name' => '被合并联系人']);
    ContactIdentity::factory()->phone()->create([
        'contact_id' => $merged->id,
        'workspace_id' => $workspace->id,
        'value' => '+8618995543120',
        'display_value' => '+8618995543120',
    ]);

    MergeContactsAction::run($workspace, $target->id, $merged->id);

    $this->actingAs($user)
        ->getJson(route('workspace.contacts.show', ['slug' => $workspace->slug, 'id' => $target->id]))
        ->assertOk()
        ->assertJsonPath('activity_logs.0.action', 'merged_into_current')
        ->assertJsonPath('activity_logs.0.related_contact_name', '被合并联系人')
        ->assertJsonPath('activity_logs.0.identity_values.0', '+8618995543120');

    $this->actingAs($user)
        ->getJson(route('workspace.contacts.show', [
            'slug' => $workspace->slug,
            'id' => $merged->id,
            'include_trashed' => 1,
        ]))
        ->assertOk()
        ->assertJsonPath('activity_logs.0.action', 'merged_into_other')
        ->assertJsonPath('activity_logs.0.related_contact_name', '目标联系人');
});

test('可以更新联系人通过HTTP', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->put(route('workspace.contacts.update', ['slug' => $workspace->slug, 'id' => $contact->id]), [
            'name' => '更新后',
            'type' => 'contact',
            'note' => '这个联系人需要优先处理',
        ])
        ->assertRedirect();

    $contact->refresh();
    expect($contact->name)->toBe('更新后')
        ->and($contact->type)->toBe(ContactType::Contact)
        ->and($contact->note)->toBe('这个联系人需要优先处理');

    $this->actingAs($user)
        ->getJson(route('workspace.contacts.show', ['slug' => $workspace->slug, 'id' => $contact->id]))
        ->assertOk()
        ->assertJsonPath('activity_logs.0.action', 'updated')
        ->assertJsonPath('activity_logs.0.actor_name', $user->name);
});

test('可以删除联系人通过HTTP', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->delete(route('workspace.contacts.destroy', ['slug' => $workspace->slug, 'id' => $contact->id]))
        ->assertRedirect();

    expect(Contact::find($contact->id))->toBeNull();
});

test('可以恢复联系人通过HTTP', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();

    DeleteContactAction::run($workspace, $contact->id);

    $this->actingAs($user)
        ->put(route('workspace.contacts.restore', ['slug' => $workspace->slug, 'id' => $contact->id]))
        ->assertRedirect();

    expect(Contact::find($contact->id))->not->toBeNull();
});

test('可以合并联系人通过HTTP', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $target = Contact::factory()->for($workspace)->create(['name' => '目标']);
    ContactIdentity::factory()->email()->create([
        'contact_id' => $target->id,
        'workspace_id' => $workspace->id,
        'value' => 'target@example.com',
    ]);

    $merged = Contact::factory()->for($workspace)->create(['name' => '被合并']);
    ContactIdentity::factory()->phone()->create([
        'contact_id' => $merged->id,
        'workspace_id' => $workspace->id,
        'value' => '+8613800138000',
    ]);

    $this->actingAs($user)
        ->post(route('workspace.contacts.merge', ['slug' => $workspace->slug]), [
            'target_contact_id' => $target->id,
            'merged_contact_id' => $merged->id,
        ])
        ->assertRedirect();

    expect(Contact::find($merged->id))->toBeNull();
});

test('可以替换联系人身份通过HTTP', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $identity = ContactIdentity::factory()->email()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
        'value' => 'old@example.com',
        'display_value' => 'old@example.com',
    ]);

    $this->actingAs($user)
        ->put(route('workspace.contacts.identities.replace', [
            'slug' => $workspace->slug,
            'contactId' => $contact->id,
            'identityId' => $identity->id,
        ]), [
            'value' => 'new@example.com',
        ])
        ->assertRedirect();

    expect(ContactIdentity::find($identity->id))->toBeNull()
        ->and($contact->fresh()->primary_email)->toBe('new@example.com');
});

test('可以删除联系人身份通过HTTP', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $identity = ContactIdentity::factory()->email()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
        'value' => 'delete-me@example.com',
        'display_value' => 'delete-me@example.com',
    ]);

    $contact->syncPrimaryFields();

    $this->actingAs($user)
        ->delete(route('workspace.contacts.identities.destroy', [
            'slug' => $workspace->slug,
            'contactId' => $contact->id,
            'identityId' => $identity->id,
        ]))
        ->assertRedirect();

    expect(ContactIdentity::find($identity->id))->toBeNull()
        ->and($contact->fresh()->primary_email)->toBeNull();
});

test('可以添加身份通过HTTP', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->post(route('workspace.contacts.identities.store', ['slug' => $workspace->slug, 'contactId' => $contact->id]), [
            'type' => 'email',
            'value' => 'added@example.com',
        ])
        ->assertRedirect();

    expect($contact->identities()->count())->toBe(1);
});

test('拒绝创建邮箱身份且无效格式', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->post(route('workspace.contacts.identities.store', ['slug' => $workspace->slug, 'contactId' => $contact->id]), [
            'type' => 'email',
            'value' => 'not-a-valid-email',
        ])
        ->assertSessionHasErrors('value');

    expect($contact->identities()->count())->toBe(0);
});

test('拒绝替换邮箱身份且无效格式', function () {
    [$workspace, $user] = createWorkspaceWithOwner();

    $contact = Contact::factory()->for($workspace)->create();
    $identity = ContactIdentity::factory()->email()->create([
        'contact_id' => $contact->id,
        'workspace_id' => $workspace->id,
        'value' => 'old@example.com',
        'display_value' => 'old@example.com',
    ]);

    $this->actingAs($user)
        ->put(route('workspace.contacts.identities.replace', [
            'slug' => $workspace->slug,
            'contactId' => $contact->id,
            'identityId' => $identity->id,
        ]), [
            'value' => 'not-a-valid-email',
        ])
        ->assertSessionHasErrors('value');

    expect(ContactIdentity::find($identity->id))->not->toBeNull();
});

test('ContactDemoSeeder会为第一个工作区填充联系人', function () {
    [$workspace] = createWorkspaceWithOwner();

    $this->artisan('db:seed', ['--class' => ContactDemoSeeder::class])
        ->assertSuccessful();

    expect(Contact::where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0);
});
