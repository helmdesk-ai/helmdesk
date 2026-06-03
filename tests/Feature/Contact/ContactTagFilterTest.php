<?php

use App\Actions\Contact\ShowContactListAction;
use App\Data\Contact\ContactTagConditionData;
use App\Data\Contact\ContactTagFilterData;
use App\Enums\ContactListType;
use App\Enums\TagMatchMode;
use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->user = $this->createUserWithSystem();

    $this->tagA = Tag::factory()->create([
        'name' => 'A',
    ]);
    $this->tagB = Tag::factory()->create([
        'name' => 'B',
    ]);
    $this->tagC = Tag::factory()->create([
        'name' => 'C',
    ]);

    $tagIdByLetter = [
        'A' => $this->tagA->id,
        'B' => $this->tagB->id,
        'C' => $this->tagC->id,
    ];

    $makeContact = function (array $letters) use ($tagIdByLetter): Contact {
        $contact = Contact::factory()->create([
            'name' => implode('', $letters),
        ]);

        foreach ($letters as $letter) {
            DB::table('contact_tag_assignments')->insert([
                'tag_id' => $tagIdByLetter[$letter],
                'contact_id' => $contact->id,
                'source' => 'manual',
                'created_at' => now(),
            ]);
        }

        return $contact;
    };

    /*
     * 构造 8 种联系人，覆盖 {A,B,C} × 有/无 的全排列，便于对每种模式下
     * 预期结果做精确断言。
     */
    $this->cNone = Contact::factory()->create(['name' => 'none']);
    $this->cA = $makeContact(['A']);
    $this->cB = $makeContact(['B']);
    $this->cC = $makeContact(['C']);
    $this->cAB = $makeContact(['A', 'B']);
    $this->cAC = $makeContact(['A', 'C']);
    $this->cBC = $makeContact(['B', 'C']);
    $this->cABC = $makeContact(['A', 'B', 'C']);
});

/*
 * 以下 helper 之所以挂在 $this 上用闭包定义，是因为它们依赖 beforeEach 里
 * 构造的 systemContext / tagA/B/C，放成全局函数反而要再传一堆参数。
 */

/**
 * @param  array<int, string>  $tagLetters
 */
function makeFilter(array $include = [], string $includeMode = 'any', array $exclude = [], string $excludeMode = 'any', bool $untaggedOnly = false): ContactTagFilterData
{
    return new ContactTagFilterData(
        include: array_map(fn (string $id) => new ContactTagConditionData(tag_id: $id), $include),
        include_mode: TagMatchMode::from($includeMode),
        exclude: array_map(fn (string $id) => new ContactTagConditionData(tag_id: $id), $exclude),
        exclude_mode: TagMatchMode::from($excludeMode),
        untagged_only: $untaggedOnly,
    );
}

/**
 * @param  array<int, string>  $tagLetters
 */
function runFilter(ContactTagFilterData $filter, $systemContext): array
{
    $props = ShowContactListAction::run(
        type: ContactListType::All,
        perPage: 50,
        tagFilter: $filter,
    );

    return array_map(fn ($c) => $c->id, $props->contact_list);
}

test('联系人列表暴露列表类型选项来自枚举', function () {
    $props = ShowContactListAction::run(
        type: ContactListType::All,
        perPage: 50,
    );

    expect(collect($props->contact_list_type_options)->pluck('value')->all())
        ->toBe([
            ContactListType::All->value,
            ContactListType::Contacts->value,
            ContactListType::Visitors->value,
        ]);
});

test('include任意返回联系人拥有至少一个的标签', function () {
    $filter = makeFilter(include: [$this->tagA->id, $this->tagB->id], includeMode: 'any');

    $ids = runFilter($filter, $this->systemContext);

    expect($ids)
        ->toContain($this->cA->id, $this->cB->id, $this->cAB->id, $this->cAC->id, $this->cBC->id, $this->cABC->id)
        ->not->toContain($this->cNone->id, $this->cC->id);
});

test('include全部返回联系人拥有每个标签', function () {
    $filter = makeFilter(include: [$this->tagA->id, $this->tagB->id], includeMode: 'all');

    $ids = runFilter($filter, $this->systemContext);

    expect($ids)
        ->toContain($this->cAB->id, $this->cABC->id)
        ->not->toContain($this->cNone->id, $this->cA->id, $this->cB->id, $this->cC->id, $this->cAC->id, $this->cBC->id);
});

test('包含任意+排除任意', function () {
    $filter = makeFilter(
        include: [$this->tagA->id, $this->tagB->id],
        includeMode: 'any',
        exclude: [$this->tagC->id],
        excludeMode: 'any',
    );

    $ids = runFilter($filter, $this->systemContext);

    expect($ids)
        ->toContain($this->cA->id, $this->cB->id, $this->cAB->id)
        ->not->toContain($this->cAC->id, $this->cBC->id, $this->cABC->id, $this->cC->id, $this->cNone->id);
});

test('包含任意+排除全部：只排除拥有所有被排除标签的联系人', function () {
    /*
     * 排除 [B,C] 的 all 语义 = "排除同时拥有 B 和 C 的联系人"
     * → 仅排除 cBC 和 cABC；cB 单独、cC 单独、cAC 都应保留。
     */
    $filter = makeFilter(
        include: [$this->tagA->id, $this->tagB->id, $this->tagC->id],
        includeMode: 'any',
        exclude: [$this->tagB->id, $this->tagC->id],
        excludeMode: 'all',
    );

    $ids = runFilter($filter, $this->systemContext);

    expect($ids)
        ->toContain($this->cA->id, $this->cB->id, $this->cC->id, $this->cAB->id, $this->cAC->id)
        ->not->toContain($this->cBC->id, $this->cABC->id, $this->cNone->id);
});

test('包含全部+排除任意', function () {
    $filter = makeFilter(
        include: [$this->tagA->id, $this->tagB->id],
        includeMode: 'all',
        exclude: [$this->tagC->id],
        excludeMode: 'any',
    );

    $ids = runFilter($filter, $this->systemContext);

    /*
     * include all [A,B] → {cAB, cABC}
     * 再排除拥有 C 的 → {cAB}
     */
    expect($ids)
        ->toContain($this->cAB->id)
        ->not->toContain($this->cABC->id, $this->cNone->id, $this->cA->id, $this->cB->id, $this->cC->id);
});

test('包含全部+排除全部', function () {
    $filter = makeFilter(
        include: [$this->tagA->id],
        includeMode: 'all',
        exclude: [$this->tagB->id, $this->tagC->id],
        excludeMode: 'all',
    );

    $ids = runFilter($filter, $this->systemContext);

    /*
     * include A → {cA, cAB, cAC, cABC}
     * exclude all [B,C]（= 排除同时拥有 B 和 C）→ 剩下 {cA, cAB, cAC}
     */
    expect($ids)
        ->toContain($this->cA->id, $this->cAB->id, $this->cAC->id)
        ->not->toContain($this->cABC->id);
});

test('untagged_only只返回无标签联系人并忽略其他条件', function () {
    $filter = makeFilter(
        include: [$this->tagA->id],
        includeMode: 'any',
        exclude: [$this->tagB->id],
        excludeMode: 'any',
        untaggedOnly: true,
    );

    $ids = runFilter($filter, $this->systemContext);

    expect($ids)->toBe([$this->cNone->id]);
});

test('空筛选返回全部联系人', function () {
    $ids = runFilter(ContactTagFilterData::unfiltered(), $this->systemContext);

    expect(count($ids))->toBe(8);
});

test('筛选限制到可用标签的系统', function () {
    $otherSystemTag = Tag::factory()->create([
    ]);

    $filter = makeFilter(include: [$otherSystemTag->id, $this->tagA->id], includeMode: 'any');

    $ids = runFilter($filter, $this->systemContext);

    expect($ids)
        ->toContain($this->cA->id, $this->cAB->id, $this->cAC->id, $this->cABC->id)
        ->not->toContain($this->cB->id, $this->cC->id, $this->cBC->id, $this->cNone->id);
});

test('include条件遵守tagged_after中间表时间戳', function () {
    DB::table('contact_tag_assignments')
        ->where('contact_id', $this->cA->id)
        ->where('tag_id', $this->tagA->id)
        ->update(['created_at' => Carbon::parse('2024-01-01 00:00:00')]);

    DB::table('contact_tag_assignments')
        ->where('contact_id', $this->cAB->id)
        ->where('tag_id', $this->tagA->id)
        ->update(['created_at' => Carbon::parse('2026-04-10 00:00:00')]);

    $filter = new ContactTagFilterData(
        include: [new ContactTagConditionData(
            tag_id: $this->tagA->id,
            tagged_after: Carbon::parse('2026-01-01 00:00:00'),
        )],
        include_mode: TagMatchMode::Any,
        exclude: [],
        exclude_mode: TagMatchMode::Any,
        untagged_only: false,
    );

    $ids = runFilter($filter, $this->systemContext);

    expect($ids)
        ->toContain($this->cAB->id)
        ->not->toContain($this->cA->id);
});

test('新查询参数支持include', function () {
    $request = new Request([
        'include_tag_ids' => [$this->tagA->id],
        'include_tag_mode' => 'any',
        'exclude_tag_ids' => [$this->tagC->id],
        'exclude_tag_mode' => 'any',
    ]);

    $filter = ContactTagFilterData::fromRequest($request);

    expect($filter->include[0]->tag_id)->toBe($this->tagA->id)
        ->and($filter->exclude[0]->tag_id)->toBe($this->tagC->id)
        ->and($filter->exclude_mode)->toBe(TagMatchMode::Any);

    $request2 = new Request([
        'untagged_only' => '1',
        'include_tag_ids' => [$this->tagA->id],
    ]);

    $filter2 = ContactTagFilterData::fromRequest($request2);

    expect($filter2->untagged_only)->toBeTrue()
        ->and($filter2->include)->toBe([]);
});

test('include和exclude的交集会被保留并由策略评估', function () {
    /*
     * include any [A,B] + exclude any [B] 合法且常见，等价于 "has A AND NOT B"
     * → 预期 {cA, cAC}（cAB/cABC 因为带 B 被排除；cB/cBC 因为带 B 被排除；
     *   cC/cNone 不在 include 集里）
     */
    $filter = makeFilter(
        include: [$this->tagA->id, $this->tagB->id],
        includeMode: 'any',
        exclude: [$this->tagB->id],
        excludeMode: 'any',
    );

    $ids = runFilter($filter, $this->systemContext);

    expect($ids)
        ->toContain($this->cA->id, $this->cAC->id)
        ->not->toContain($this->cB->id, $this->cAB->id, $this->cBC->id, $this->cABC->id, $this->cC->id, $this->cNone->id);
});

test('HTTP层会保留交集', function () {
    $request = new Request([
        'include_tag_ids' => [$this->tagA->id, $this->tagB->id],
        'exclude_tag_ids' => [$this->tagB->id],
    ]);

    $filter = ContactTagFilterData::fromRequest($request);

    expect($filter->include)->toHaveCount(2)
        ->and($filter->exclude)->toHaveCount(1)
        ->and($filter->exclude[0]->tag_id)->toBe($this->tagB->id);
});

test('矛盾筛选（include A且exclude A）返回空结果', function () {
    /*
     * include all [A] + exclude any [A] → has A AND NOT has A = ∅
     * 这条查询本身是矛盾的，策略层不应为了"容错"而悄悄放宽；空集才是正确答案。
     */
    $filter = makeFilter(
        include: [$this->tagA->id],
        includeMode: 'all',
        exclude: [$this->tagA->id],
        excludeMode: 'any',
    );

    $ids = runFilter($filter, $this->systemContext);

    expect($ids)->toBe([]);
});

test('HTTP解析untagged_only', function () {
    $request = new Request([
        'untagged_only' => 'true',
        'include_tag_ids' => [$this->tagA->id, $this->tagB->id],
        'exclude_tag_ids' => [$this->tagC->id],
        'include_tag_mode' => 'all',
    ]);

    $filter = ContactTagFilterData::fromRequest($request);

    expect($filter->untagged_only)->toBeTrue()
        ->and($filter->include)->toBe([])
        ->and($filter->exclude)->toBe([]);
});

test('HTTP解析空白和重复', function () {
    $request = new Request([
        'include_tag_ids' => [$this->tagA->id, '', '   ', $this->tagA->id, $this->tagB->id],
        'include_tag_mode' => 'any',
    ]);

    $filter = ContactTagFilterData::fromRequest($request);

    expect($filter->include_mode)->toBe(TagMatchMode::Any)
        ->and($filter->include)->toHaveCount(2)
        ->and($filter->include[0]->tag_id)->toBe($this->tagA->id)
        ->and($filter->include[1]->tag_id)->toBe($this->tagB->id);
});

test('HTTP解析拒绝无效标签模式', function () {
    $this->actingAs($this->user)
        ->from(route('admin.contacts.index', ['type' => 'all',
        ]))
        ->get(route('admin.contacts.index', ['type' => 'all',
            'include_tag_ids' => [$this->tagA->id],
            'include_tag_mode' => 'bogus',
        ]))
        ->assertRedirect(route('admin.contacts.index', ['type' => 'all',
        ]))
        ->assertSessionHasErrors('include_tag_mode');
});

test('HTTP解析拒绝无效标签日期边界', function () {
    $this->actingAs($this->user)
        ->from(route('admin.contacts.index', ['type' => 'all',
        ]))
        ->get(route('admin.contacts.index', ['type' => 'all',
            'include_tag_ids' => [$this->tagA->id],
            'tag_tagged_after' => '2026/01/01',
        ]))
        ->assertRedirect(route('admin.contacts.index', ['type' => 'all',
        ]))
        ->assertSessionHasErrors('tag_tagged_after');
});
