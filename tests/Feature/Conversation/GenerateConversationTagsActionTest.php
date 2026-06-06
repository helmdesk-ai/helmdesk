<?php

use App\Actions\Conversation\GenerateConversationTagsAction;
use App\Data\Conversation\GeneratedConversationTagData;
use App\Enums\AiModelType;
use App\Enums\AiProviderProtocol;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\TagGroup;
use App\Services\Conversation\GoConversationSummaryBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

/**
 * 构造记录入参并返回固定建议的假桥接，用于在不调真实 LLM 的前提下测试编排逻辑。
 */
function fakeConversationTagsBridge(): object
{
    return new class extends GoConversationSummaryBridge
    {
        /** @var list<array{tag_id: string, name: string, description: ?string, group: ?string}> */
        public array $receivedCandidates = [];

        /** @var list<GeneratedConversationTagData> */
        public array $stubbedTags = [];

        /**
         * 跳过父类对 GoBridgeClient 的依赖。
         */
        public function __construct() {}

        /**
         * 记录候选词表并返回预设标签建议。
         */
        public function generateConversationTags(
            AiProvider $provider,
            AiModel $model,
            string $locale,
            array $candidates,
            ?string $summary = null,
            array $messages = [],
        ): array {
            $this->receivedCandidates = $candidates;

            return $this->stubbedTags;
        }
    };
}

beforeEach(function () {
    $this->user = $this->createUserWithSystem();
    $this->contact = Contact::factory()->create([]);
    $this->conversation = Conversation::factory()->create([
        'contact_id' => $this->contact->id,
        'summary' => '客户咨询退款流程',
        'summary_last_message_seq_no' => 12,
    ]);

    // 提供一个可用的接待 LLM 模型，让候选解析的兜底分支命中。
    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'fake-openai',
        'name' => 'Fake',
        'protocol' => AiProviderProtocol::OpenAI,
        'credentials' => ['api_key' => 'x'],
        'credential_fields' => [],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'gpt-x',
        'name' => 'gpt-x',
        'type' => AiModelType::Llm->value,
        'is_active' => true,
    ]);

    $this->conversationGroup = TagGroup::factory()->conversation()->create([]);
    $this->contactGroup = TagGroup::factory()->contact()->create([]);
});

test('词表只下发会话维度标签，且按阈值映射回标签落库', function () {
    $refund = Tag::factory()->forGroup($this->conversationGroup)->create(['name' => '退款', 'description' => '客户要求退款时']);
    Tag::factory()->forGroup($this->contactGroup)->create(['name' => 'VIP']);

    $fake = fakeConversationTagsBridge();
    $fake->stubbedTags = [
        new GeneratedConversationTagData('退款', 0.9, '客户说要退款', (string) $refund->id),
        new GeneratedConversationTagData('退款', 0.3, '低置信被过滤', (string) $refund->id),
        new GeneratedConversationTagData('不存在的标签', 0.95, '幻觉', '01HALLUCINATEDTAGID'),
    ];
    $this->app->instance(GoConversationSummaryBridge::class, $fake);

    GenerateConversationTagsAction::make()->handle($this->conversation, finalize: false);

    // 词表只包含会话维度的「退款」，不含联系人维度的 VIP
    expect(collect($fake->receivedCandidates)->pluck('name')->all())->toBe(['退款']);

    $tags = $this->conversation->tags()->get();
    expect($tags)->toHaveCount(1);
    expect($tags->first()->name)->toBe('退款');
    expect($tags->first()->pivot->source)->toBe('ai');
    expect((int) $tags->first()->pivot->based_on_seq_no)->toBe(12);
});

test('低置信度建议不落库', function () {
    $tag = Tag::factory()->forGroup($this->conversationGroup)->create(['name' => '技术支持']);

    $fake = fakeConversationTagsBridge();
    $fake->stubbedTags = [new GeneratedConversationTagData('技术支持', 0.2, '不确定', (string) $tag->id)];
    $this->app->instance(GoConversationSummaryBridge::class, $fake);

    GenerateConversationTagsAction::make()->handle($this->conversation, finalize: false);

    expect($this->conversation->tags()->count())->toBe(0);
});

test('同名标签按 tag_id 映射，允许不同分组使用相同名称', function () {
    $moodGroup = TagGroup::factory()->conversation()->create(['name' => '脾气']);
    $styleGroup = TagGroup::factory()->conversation()->create(['name' => '语气']);
    $moodTag = Tag::factory()->forGroup($moodGroup)->create(['name' => '温和']);
    $styleTag = Tag::factory()->forGroup($styleGroup)->create(['name' => '温和']);

    $fake = fakeConversationTagsBridge();
    $fake->stubbedTags = [new GeneratedConversationTagData('温和', 0.9, '命中语气分组', (string) $styleTag->id)];
    $this->app->instance(GoConversationSummaryBridge::class, $fake);

    GenerateConversationTagsAction::make()->handle($this->conversation, finalize: false);

    $tagIds = $this->conversation->tags()->pluck('tags.id')->all();
    expect($tagIds)->toContain($styleTag->id);
    expect($tagIds)->not->toContain($moodTag->id);
});
