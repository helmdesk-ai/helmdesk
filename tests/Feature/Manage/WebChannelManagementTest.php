<?php

use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Data\Channel\Web\WebChannelData;
use App\Enums\Channel\Web\WebChannelVisitorIdentityMode;
use App\Enums\Channel\Web\WebChannelWidgetEntryMode;
use App\Enums\Channel\Web\WebChannelWidgetEntryPosition;
use App\Enums\Channel\Web\WebChannelWidgetEntryStyle;
use App\Enums\Channel\Web\WebChannelWidgetIconSize;
use App\Enums\ReceptionLanguage;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\SystemContext;
use App\Models\User;
use App\Services\Channel\WebChannelThemePalette;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = $this->createUserWithSystem();
    config(['app.url' => 'https://helmdesk.test']);

    $settings = app(GeneralSettings::class);
    $settings->base_url = 'https://helmdesk.test';
    $settings->save();
});

function createChannelTestProvider(array $attributes = []): AiProvider
{
    /** @var SystemContext $systemContext */
    $systemContext = test()->systemContext;

    return AiProvider::query()->create(array_merge([
        'brand' => 'custom-openai',
        'slug' => 'test-provider-channel-'.Str::lower(Str::random(6)),
        'name' => 'Test Provider',
        'protocol' => 'openai',
        'credentials' => ['api_key' => 'test-key'],
        'credential_fields' => [['field' => 'api_key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ], $attributes));
}

function createChannelTestModel(AiProvider $provider, array $attributes = []): AiModel
{
    return AiModel::query()->create(array_merge([
        'ai_provider_id' => $provider->id,
        'name' => 'Channel Model',
        'model_id' => 'gpt-4.1-mini',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ], $attributes));
}

function createChannelTestAttachment(array $attributes = []): Attachment
{
    /** @var SystemContext $systemContext */
    $systemContext = test()->systemContext;

    return Attachment::factory()->create(array_merge([
        'disk' => 'local',
        'object_key' => 'uploads/'.Str::lower(Str::random(8)).'.png',
        'original_name' => 'test.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 1024,
        'visibility' => 'public',
        'purpose' => 'channel_icon',
        'status' => 'uploaded',
    ], $attributes));
}

/**
 * 创建一个可被渠道直接部署的接待方案版本：插入到当前 systemContext、状态 published、
 * 接待 / 任务默认模型指向给定 AiModel，AiModelResolver 能据此判定为可用。
 */
function createDeployableReceptionPlanVersion(SystemContext $systemContext, ?AiModel $model = null, array $versionAttributes = []): ReceptionPlanVersion
{
    if ($model === null) {
        $provider = createChannelTestProvider();
        $model = createChannelTestModel($provider);
    }

    $plan = ReceptionPlan::factory()->create([
        'name' => '官网接待方案-'.Str::lower(Str::random(6)),
    ]);

    return ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->withReceptionModel($model->id)
        ->create($versionAttributes);
}

test('所有者可以查看网页频道列表和详情页面', function () {
    $version = createDeployableReceptionPlanVersion($this->systemContext);
    $planName = $version->plan->name;

    $channel = Channel::factory()->create([
        'name' => '官网主站',
        'description' => '官网入口备注',
        'reception_plan_id' => $version->reception_plan_id,
        'settings' => ChannelWebSettingsData::defaults([
            'visitor_interface' => [
                'site_name' => 'HelmDesk 官网',
                'greeting_message' => '欢迎咨询',
                'theme_color' => '#14B8A6',
                'home_mode_enabled' => true,
                'home_welcome_message' => '欢迎你！',
            ],
        ]),
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/List')
            ->has('channel_list', 1)
            ->where('channel_list_pagination.current_page', 1)
            ->where('channel_list.0.name', '官网主站')
            ->where('channel_list.0.description', '官网入口备注')
            ->where('channel_list.0.standalone_url', 'https://helmdesk.test/ch/'.$channel->code)
            ->where('channel_list.0.reception_plan_id', (string) $version->reception_plan_id)
            ->where('channel_list.0.reception_plan_name', $planName)
        );

    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/Create')
            ->has('reception_plan_options', 1)
            ->where('reception_plan_options.0.id', (string) $version->reception_plan_id)
            ->where('reception_plan_options.0.is_usable', true)
            ->has('reception_language_options')
        );

    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.show', ['channel' => $channel->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/Show')
            ->where('web_channel.id', (string) $channel->id)
            ->where('web_channel.description', '官网入口备注')
            ->where('web_channel.reception_plan_id', (string) $version->reception_plan_id)
            ->where('web_channel.reception_plan_name', $planName)
            ->where('web_channel.visitor_interface.site_name', 'HelmDesk 官网')
            ->where('web_channel.visitor_interface.greeting_message', '欢迎咨询')
            ->where('web_channel.visitor_interface.theme_color', '#14B8A6')
            ->where('web_channel.visitor_interface.home_mode_enabled', true)
            ->where('web_channel.visitor_interface.home_welcome_message', '欢迎你！')
            ->where('web_channel.visitor_interface.header.enabled', false)
            ->where('web_channel.suggestions.enabled', false)
            ->where('web_channel.widget.entry.mode', WebChannelWidgetEntryMode::Bubble->value)
            ->where('web_channel.widget.entry.position', WebChannelWidgetEntryPosition::Right->value)
            ->where('web_channel.widget.entry.style', WebChannelWidgetEntryStyle::System->value)
            ->where('web_channel.widget.entry.icon_size', WebChannelWidgetIconSize::Large->value)
            ->where('web_channel.widget.entry.bottom_offset', 30)
            ->where('web_channel.widget.unread_badge_enabled', false)
            ->where('web_channel.widget.mobile_fullscreen_enabled', true)
            ->where('web_channel.widget_snippet', "<script async src=\"https://helmdesk.test/embed/widget.js\" data-channel-code=\"{$channel->code}\"></script>")
            ->has('form_options.reception_plan_options')
            ->has('form_options.visitor_identity_mode_options', 2)
            ->where('form_options.visitor_identity_mode_options.0.value', WebChannelVisitorIdentityMode::ActualReceptionist->value)
            ->where('form_options.visitor_identity_mode_options.0.label', WebChannelVisitorIdentityMode::ActualReceptionist->label())
            ->has('form_options.query_param_options', 8)
            ->has('form_options.theme_color_options', count(WebChannelThemePalette::presets()))
            ->where('form_options.theme_color_options.0', WebChannelThemePalette::DEFAULT)
            ->has('form_options.widget_entry_mode_options', 2)
            ->where('form_options.widget_entry_mode_options.0.value', WebChannelWidgetEntryMode::Bubble->value)
            ->where('form_options.widget_entry_mode_options.0.label', WebChannelWidgetEntryMode::Bubble->label())
            ->has('form_options.widget_entry_position_options', 2)
            ->where('form_options.widget_entry_position_options.0.value', WebChannelWidgetEntryPosition::Right->value)
            ->where('form_options.widget_entry_position_options.0.label', WebChannelWidgetEntryPosition::Right->label())
            ->has('form_options.widget_entry_style_options', 2)
            ->has('form_options.widget_icon_size_options', 3)
        );
});

test('网页频道列表与详情会回填 embed host 跟踪字段', function () {
    $version = createDeployableReceptionPlanVersion($this->systemContext);

    $firstEmbedAt = CarbonImmutable::parse('2026-04-20T03:15:00Z');
    $lastEmbedAt = CarbonImmutable::parse('2026-05-20T08:42:00Z');

    $channel = Channel::factory()->create([
        'name' => '官网主站',
        'reception_plan_id' => $version->reception_plan_id,
        'first_embed_host' => 'foo.example.com',
        'first_embed_at' => $firstEmbedAt,
        'last_embed_host' => 'bar.example.com',
        'last_embed_at' => $lastEmbedAt,
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/List')
            ->where('channel_list.0.first_embed_host', 'foo.example.com')
            ->where('channel_list.0.first_embed_at', $firstEmbedAt->toIso8601String())
            ->where('channel_list.0.last_embed_host', 'bar.example.com')
            ->where('channel_list.0.last_embed_at', $lastEmbedAt->toIso8601String())
        );

    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.show', ['channel' => $channel->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/Show')
            ->where('web_channel.first_embed_host', 'foo.example.com')
            ->where('web_channel.first_embed_at', $firstEmbedAt->toIso8601String())
            ->where('web_channel.last_embed_host', 'bar.example.com')
            ->where('web_channel.last_embed_at', $lastEmbedAt->toIso8601String())
        );
});

test('网页频道未被嵌入时 embed host 字段为 null', function () {
    $version = createDeployableReceptionPlanVersion($this->systemContext);

    $channel = Channel::factory()->create([
        'name' => '帮助中心',
        'reception_plan_id' => $version->reception_plan_id,
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.show', ['channel' => $channel->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/Show')
            ->where('web_channel.first_embed_host', null)
            ->where('web_channel.first_embed_at', null)
            ->where('web_channel.last_embed_host', null)
            ->where('web_channel.last_embed_at', null)
        );
});

test('所有者可以查看网页频道列表且没有可选接待方案版本', function () {
    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/List')
            ->has('channel_list', 0)
            ->where('channel_list_pagination.current_page', 1)
            ->where('channel_list_pagination.total', 0)
        );

    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/Create')
            ->has('reception_plan_options', 0)
        );
});

test('网站渠道预览地址使用后台保存的主机地址', function () {
    config(['app.url' => GeneralSettings::DEFAULT_BASE_URL]);

    $settings = app(GeneralSettings::class);
    $settings->base_url = 'http://localhost:8080';
    $settings->save();

    $version = createDeployableReceptionPlanVersion($this->systemContext);

    $channel = Channel::factory()->create([
        'name' => '本地开发站点',
        'reception_plan_id' => $version->reception_plan_id,
    ]);

    $this->actingAs($this->user)
        ->get("http://localhost:8080/admin/manage/channels/web/{$channel->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/Show')
            ->where('web_channel.standalone_url', 'http://localhost:8080/ch/'.$channel->code)
            ->where('web_channel.widget_snippet', "<script async src=\"http://localhost:8080/embed/widget.js\" data-channel-code=\"{$channel->code}\"></script>")
        );
});

test('所有者可以创建活跃频道并带名称和接待方案版本', function () {
    $version = createDeployableReceptionPlanVersion($this->systemContext);

    $response = $this->actingAs($this->user)
        ->from(route('admin.manage.channels.web.index'))
        ->post(route('admin.manage.channels.web.store'), [
            'name' => '帮助中心',
            'description' => '用于帮助中心页面的内部备注',
            'reception_plan_id' => $version->reception_plan_id,
            'default_visitor_locale' => ReceptionLanguage::Japanese->value,
        ]);

    $channel = Channel::query()
        ->firstOrFail();
    $settings = $channel->settings;

    $response->assertRedirect(route('admin.manage.channels.web.show', ['channel' => $channel->id,
    ]));

    expect($channel->name)->toBe('帮助中心')
        ->and($channel->description)->toBe('用于帮助中心页面的内部备注')
        ->and($channel->reception_plan_id)->toBe($version->reception_plan_id)
        ->and($channel->code)->toStartWith('wch_')
        ->and(strlen($channel->code))->toBe(16)
        ->and($settings)->toBeInstanceOf(ChannelWebSettingsData::class)
        ->and(strlen((string) $settings->user_token_secret))->toBe(64)
        ->and(WebChannelData::fromModel($channel)->user_token_secret_masked)->toBe(substr((string) $settings->user_token_secret, 0, 8).'********'.substr((string) $settings->user_token_secret, -8))
        ->and(WebChannelData::fromModel($channel)->user_token_secret)->toBe($settings->user_token_secret)
        ->and($settings->allowed_embed_hosts)->toBe(['*'])
        ->and($settings->default_visitor_locale)->toBe(ReceptionLanguage::Japanese)
        ->and($settings->visitor_interface->theme_color)->toBe(WebChannelThemePalette::DEFAULT)
        ->and($settings->visitor_interface->home_mode_enabled)->toBeFalse()
        ->and($settings->visitor_interface->header?->enabled)->toBeFalse()
        ->and($settings->suggestions->enabled)->toBeFalse()
        ->and($settings->widget->entry?->mode)->toBe(WebChannelWidgetEntryMode::Bubble)
        ->and($settings->widget->unread_badge_enabled)->toBeFalse()
        ->and($settings->widget->mobile_fullscreen_enabled)->toBeTrue();
});

test('创建频道需要名称', function () {
    createDeployableReceptionPlanVersion($this->systemContext);

    $this->actingAs($this->user)
        ->from(route('admin.manage.channels.web.create'))
        ->post(route('admin.manage.channels.web.store'), [
        ])
        ->assertSessionHasErrors(['name']);
});

test('创建频道时不部署接待方案版本则被拒绝', function () {
    createDeployableReceptionPlanVersion($this->systemContext);

    $this->actingAs($this->user)
        ->from(route('admin.manage.channels.web.create'))
        ->post(route('admin.manage.channels.web.store'), [
            'name' => '官网主站',
        ])
        ->assertSessionHasErrors(['reception_plan_id']);
});

test('所有者可以保存基础频道信息', function () {
    $version = createDeployableReceptionPlanVersion($this->systemContext);

    $channel = Channel::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.channels.web.show', ['channel' => $channel->id]))
        ->put(route('admin.manage.channels.web.basic.update', ['channel' => $channel->id]), [
            'name' => '帮助中心',
            'description' => '帮助中心渠道备注',
            'reception_plan_id' => $version->reception_plan_id,
            'default_visitor_locale' => ReceptionLanguage::Japanese->value,
        ])
        ->assertRedirect(route('admin.manage.channels.web.show', ['channel' => $channel->id]));

    $channel->refresh();
    $settings = $channel->settings;

    expect($channel->name)->toBe('帮助中心')
        ->and($channel->description)->toBe('帮助中心渠道备注')
        ->and($channel->reception_plan_id)->toBe($version->reception_plan_id)
        ->and($settings)->toBeInstanceOf(ChannelWebSettingsData::class)
        ->and($settings->default_visitor_locale)->toBe(ReceptionLanguage::Japanese);
});

test('所有者可以保存小部件入口配置', function () {
    $channel = Channel::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.widget.update', ['channel' => $channel->id]), [
            'entry_mode' => WebChannelWidgetEntryMode::Bubble->value,
            'entry_position' => WebChannelWidgetEntryPosition::Left->value,
            'entry_style' => WebChannelWidgetEntryStyle::System->value,
            'entry_icon_size' => WebChannelWidgetIconSize::Small->value,
            'entry_bottom_offset' => 42,
            'unread_badge_enabled' => '1',
            'inline_toast_enabled' => '0',
            'mobile_fullscreen_enabled' => '0',
        ])
        ->assertRedirect(route('admin.manage.channels.web.show', ['channel' => $channel->id]));

    $channel->refresh();

    expect($channel->settings->widget->entry?->mode)->toBe(WebChannelWidgetEntryMode::Bubble)
        ->and($channel->settings->widget->entry?->position)->toBe(WebChannelWidgetEntryPosition::Left)
        ->and($channel->settings->widget->entry?->style)->toBe(WebChannelWidgetEntryStyle::System)
        ->and($channel->settings->widget->entry?->icon_size)->toBe(WebChannelWidgetIconSize::Small)
        ->and($channel->settings->widget->entry?->bottom_offset)->toBe(42)
        ->and($channel->settings->widget->unread_badge_enabled)->toBeTrue()
        ->and($channel->settings->widget->inline_toast_enabled)->toBeFalse()
        ->and($channel->settings->widget->mobile_fullscreen_enabled)->toBeFalse();
});

test('接入方式表单仅更新嵌入域名白名单且不影响入口配置', function () {
    $channel = Channel::factory()->create([
        'settings' => ChannelWebSettingsData::defaults([
            'widget' => [
                'entry' => [
                    'position' => WebChannelWidgetEntryPosition::Left->value,
                    'bottom_offset' => 42,
                ],
                'unread_badge_enabled' => true,
            ],
        ]),
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.access.update', ['channel' => $channel->id]), [
            'allowed_embed_hosts' => ['  Example.com', 'example.com', 'https://docs.example.com/install'],
        ])
        ->assertRedirect(route('admin.manage.channels.web.show', ['channel' => $channel->id]));

    $channel->refresh();

    expect($channel->settings->allowed_embed_hosts)->toBe(['example.com', 'docs.example.com'])
        // 入口配置不受接入方式表单影响。
        ->and($channel->settings->widget->entry?->position)->toBe(WebChannelWidgetEntryPosition::Left)
        ->and($channel->settings->widget->entry?->bottom_offset)->toBe(42)
        ->and($channel->settings->widget->unread_badge_enabled)->toBeTrue();
});

test('自定义入口样式可成对上传默认图标与选中图标并解析出 URL', function () {
    $channel = Channel::factory()->create([
    ]);
    $defaultIcon = createChannelTestAttachment();
    $activeIcon = createChannelTestAttachment();

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.widget.update', ['channel' => $channel->id]), [
            'entry_mode' => WebChannelWidgetEntryMode::Bubble->value,
            'entry_position' => WebChannelWidgetEntryPosition::Right->value,
            'entry_style' => WebChannelWidgetEntryStyle::Custom->value,
            'entry_icon_size' => WebChannelWidgetIconSize::Large->value,
            'entry_bottom_offset' => 30,
            'entry_default_icon_id' => $defaultIcon->id,
            'entry_active_icon_id' => $activeIcon->id,
            'unread_badge_enabled' => '0',
            'inline_toast_enabled' => '0',
            'mobile_fullscreen_enabled' => '1',
        ])
        ->assertRedirect(route('admin.manage.channels.web.show', ['channel' => $channel->id]));

    $channel->refresh();

    expect($channel->settings->widget->entry?->mode)->toBe(WebChannelWidgetEntryMode::Bubble)
        ->and($channel->settings->widget->entry?->style)->toBe(WebChannelWidgetEntryStyle::Custom)
        ->and($channel->settings->widget->entry?->default_icon_id)->toBe((string) $defaultIcon->id)
        ->and($channel->settings->widget->entry?->active_icon_id)->toBe((string) $activeIcon->id);

    // 附件被绑定到渠道，且管理端展示数据解析出可访问 URL。
    expect($defaultIcon->fresh()->attachable_id)->toBe((string) $channel->id)
        ->and($activeIcon->fresh()->attachable_id)->toBe((string) $channel->id);

    $widget = WebChannelData::fromModel($channel->fresh())->widget;
    expect($widget->entry->default_icon_url)->not->toBeNull()
        ->and($widget->entry->active_icon_url)->not->toBeNull();
});

test('自定义入口图标只传其一会被拒绝', function () {
    $channel = Channel::factory()->create([
    ]);
    $defaultIcon = createChannelTestAttachment();

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.widget.update', ['channel' => $channel->id]), [
            'entry_mode' => WebChannelWidgetEntryMode::Bubble->value,
            'entry_position' => WebChannelWidgetEntryPosition::Right->value,
            'entry_style' => WebChannelWidgetEntryStyle::Custom->value,
            'entry_icon_size' => WebChannelWidgetIconSize::Large->value,
            'entry_bottom_offset' => 30,
            'entry_default_icon_id' => $defaultIcon->id,
            'unread_badge_enabled' => '0',
            'inline_toast_enabled' => '0',
            'mobile_fullscreen_enabled' => '1',
        ])
        ->assertSessionHasErrors('entry_active_icon_id');
});

test('自定义入口模式隐藏默认气泡并放宽图标成对校验', function () {
    $channel = Channel::factory()->create([
    ]);
    $defaultIcon = createChannelTestAttachment();

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.widget.update', ['channel' => $channel->id]), [
            'entry_mode' => WebChannelWidgetEntryMode::Custom->value,
            'entry_position' => WebChannelWidgetEntryPosition::Left->value,
            'entry_style' => WebChannelWidgetEntryStyle::Custom->value,
            'entry_icon_size' => WebChannelWidgetIconSize::Large->value,
            'entry_bottom_offset' => 24,
            'entry_default_icon_id' => $defaultIcon->id,
            'unread_badge_enabled' => '1',
            'inline_toast_enabled' => '1',
            'mobile_fullscreen_enabled' => '1',
        ])
        ->assertRedirect(route('admin.manage.channels.web.show', ['channel' => $channel->id]));

    $channel->refresh();

    expect($channel->settings->widget->entry?->mode)->toBe(WebChannelWidgetEntryMode::Custom)
        ->and($channel->settings->widget->entry?->position)->toBe(WebChannelWidgetEntryPosition::Left)
        ->and($channel->settings->widget->entry?->default_icon_id)->toBeNull()
        ->and($channel->settings->widget->entry?->active_icon_id)->toBeNull()
        ->and($channel->settings->widget->unread_badge_enabled)->toBeFalse()
        ->and($channel->settings->widget->inline_toast_enabled)->toBeFalse()
        ->and($channel->settings->widget->mobile_fullscreen_enabled)->toBeTrue()
        ->and($defaultIcon->fresh()->attachable_id)->toBeNull();
});

test('所有者可以保存访客界面配置并同步到两个入口', function () {
    Storage::fake('public');

    $attachment = createChannelTestAttachment();
    $serviceAvatar = createChannelTestAttachment(['purpose' => 'avatar']);
    $channel = Channel::factory()->create([
    ]);

    $detailUrl = route('admin.manage.channels.web.show', ['channel' => $channel->id,
        'tab' => 'visitor-interface',
    ]);

    $this->actingAs($this->user)
        ->from($detailUrl)
        ->put(route('admin.manage.channels.web.visitor-interface.update', ['channel' => $channel->id]), [
            'site_name' => '帮助中心',
            'subtitle' => '产品咨询与售后支持',
            'header_enabled' => '1',
            'icon_id' => $attachment->id,
            'visitor_identity_mode' => WebChannelVisitorIdentityMode::UnifiedService->value,
            'service_display_name' => '智能客服',
            'service_avatar_id' => $serviceAvatar->id,
            'greeting_message' => '欢迎来到帮助中心',
            'composer_placeholder' => '请输入您的问题',
            'theme_color' => '#7C3AED',
            'home_mode_enabled' => '1',
            'home_welcome_message' => '👋欢迎你！',
        ])
        ->assertRedirect($detailUrl);

    $channel->refresh();
    $visitorInterface = $channel->settings->visitor_interface;

    expect($visitorInterface->site_name)->toBe('帮助中心')
        ->and($visitorInterface->theme_color)->toBe('#7C3AED')
        ->and($visitorInterface->home_mode_enabled)->toBeTrue()
        ->and($visitorInterface->home_welcome_message)->toBe('👋欢迎你！')
        ->and($visitorInterface->subtitle)->toBe('产品咨询与售后支持')
        ->and($visitorInterface->header?->enabled)->toBeTrue()
        ->and($visitorInterface->icon_id)->toBe($attachment->id)
        ->and($attachment->fresh()->attachable_id)->toBe($channel->id)
        ->and($visitorInterface->visitor_identity_mode)->toBe(WebChannelVisitorIdentityMode::UnifiedService)
        ->and($visitorInterface->service_display_name)->toBe('智能客服')
        ->and($visitorInterface->service_avatar_id)->toBe($serviceAvatar->id)
        ->and($serviceAvatar->fresh()->attachable_id)->toBe($channel->id)
        ->and($visitorInterface->greeting_message)->toBe('欢迎来到帮助中心')
        ->and($visitorInterface->composer_placeholder)->toBe('请输入您的问题');

    $webChannel = WebChannelData::fromModel($channel);

    expect($webChannel->visitor_interface->site_name)->toBe('帮助中心')
        ->and($webChannel->visitor_interface->subtitle)->toBe('产品咨询与售后支持');
});

test('所有者可以随访客界面保存猜你想问设置', function () {
    $channel = Channel::factory()->create([
    ]);

    $detailUrl = route('admin.manage.channels.web.show', ['channel' => $channel->id,
        'tab' => 'visitor-interface',
    ]);

    $this->actingAs($this->user)
        ->from($detailUrl)
        ->put(route('admin.manage.channels.web.visitor-interface.update', ['channel' => $channel->id]), [
            'header_enabled' => '0',
            'visitor_identity_mode' => WebChannelVisitorIdentityMode::ActualReceptionist->value,
            'theme_color' => WebChannelThemePalette::DEFAULT,
            'home_mode_enabled' => '0',
            'suggestions_enabled' => '1',
            'suggestion_items' => ['怎么收费？', '怎么接入？', '怎么收费？', ''],
        ])
        ->assertRedirect($detailUrl);

    $channel->refresh();

    expect($channel->settings->suggestions->enabled)->toBeTrue()
        ->and($channel->settings->suggestions->items)->toBe(['怎么收费？', '怎么接入？'])
        ->and(WebChannelData::fromModel($channel)->suggestions->items)->toBe(['怎么收费？', '怎么接入？']);
});

test('访客界面校验标题栏和身份模式', function () {
    $channel = Channel::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.visitor-interface.update', ['channel' => $channel->id]), [
            'site_name' => '',
            'subtitle' => str_repeat('副', 121),
            'header_enabled' => '1',
            'visitor_identity_mode' => 'unknown',
            'composer_placeholder' => '请输入您的问题',
            'theme_color' => WebChannelThemePalette::DEFAULT,
            'home_mode_enabled' => '0',
        ])
        ->assertSessionHasErrors([
            'site_name',
            'subtitle',
            'visitor_identity_mode',
        ]);
});

test('访客界面校验主题色与首页欢迎语', function () {
    $channel = Channel::factory()->create([
    ]);

    // 非预设色板的主题色被拒绝；首页模式开启时欢迎语必填。
    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.visitor-interface.update', ['channel' => $channel->id]), [
            'header_enabled' => '0',
            'visitor_identity_mode' => WebChannelVisitorIdentityMode::ActualReceptionist->value,
            'theme_color' => '#123456',
            'home_mode_enabled' => '1',
            'home_welcome_message' => '',
        ])
        ->assertSessionHasErrors([
            'theme_color',
            'home_welcome_message',
        ]);
});

test('访客界面校验猜你想问建议数量', function () {
    $channel = Channel::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.visitor-interface.update', ['channel' => $channel->id]), [
            'header_enabled' => '0',
            'visitor_identity_mode' => WebChannelVisitorIdentityMode::ActualReceptionist->value,
            'theme_color' => WebChannelThemePalette::DEFAULT,
            'home_mode_enabled' => '0',
            'suggestions_enabled' => '1',
            'suggestion_items' => ['1', '2', '3', '4', '5', '6', '7'],
        ])
        ->assertSessionHasErrors([
            'suggestion_items',
        ]);
});

test('所有者可以软删除频道', function () {
    $version = createDeployableReceptionPlanVersion($this->systemContext);

    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('admin.manage.channels.web.destroy', ['channel' => $channel->id]))
        ->assertRedirect(route('admin.manage.channels.web.index'));

    $this->assertSoftDeleted('channels', [
        'id' => $channel->id,
    ]);
});

test('保留当前部署不可用版本时仍能保存其它字段', function () {
    $provider = createChannelTestProvider();
    $model = createChannelTestModel($provider);
    $version = createDeployableReceptionPlanVersion($this->systemContext, $model);

    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
    ]);

    $model->update(['is_active' => false]);

    $this->actingAs($this->user)
        ->from(route('admin.manage.channels.web.show', ['channel' => $channel->id]))
        ->put(route('admin.manage.channels.web.basic.update', ['channel' => $channel->id]), [
            'name' => '改个名字',
            'reception_plan_id' => $version->reception_plan_id,
        ])
        ->assertRedirect(route('admin.manage.channels.web.show', ['channel' => $channel->id]));

    expect($channel->fresh()->name)->toBe('改个名字')
        ->and($channel->fresh()->reception_plan_id)->toBe($version->reception_plan_id);
});

test('切换到不可用接待方案版本会被拒绝', function () {
    $provider = createChannelTestProvider();
    $currentModel = createChannelTestModel($provider);
    $currentVersion = createDeployableReceptionPlanVersion($this->systemContext, $currentModel);

    $brokenModel = createChannelTestModel($provider, [
        'is_active' => false,
        'model_id' => 'gpt-4.1-broken',
    ]);
    $brokenVersion = createDeployableReceptionPlanVersion($this->systemContext, $brokenModel);

    $channel = Channel::factory()->create([
        'reception_plan_id' => $currentVersion->reception_plan_id,
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.basic.update', ['channel' => $channel->id]), [
            'name' => '试图切换',
            'reception_plan_id' => $brokenVersion->reception_plan_id,
        ])
        ->assertUnprocessable()
        ->assertJson([
            'message' => __('channel.messages.reception_plan_version_model_unavailable'),
        ]);

    expect($channel->fresh()->reception_plan_id)->toBe($currentVersion->reception_plan_id);
});

test('详情页面暴露 reception_plan_status_detail 用于已部署版本', function () {
    $provider = createChannelTestProvider();
    $model = createChannelTestModel($provider);
    $version = createDeployableReceptionPlanVersion($this->systemContext, $model);

    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
    ]);

    $model->update(['is_active' => false]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.show', ['channel' => $channel->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/Show')
            ->where('web_channel.reception_plan_id', (string) $version->reception_plan_id)
            ->where('web_channel.reception_plan_status_detail.is_valid', false)
        );
});

test('单租户下基础更新可以使用任意接待方案版本', function () {
    $version = createDeployableReceptionPlanVersion($this->systemContext);

    $channel = Channel::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.basic.update', ['channel' => $channel->id]), [
            'name' => '新的版本',
            'reception_plan_id' => $version->reception_plan_id,
        ])
        ->assertRedirect();

    expect($channel->fresh()->reception_plan_id)->toBe($version->reception_plan_id);
});

test('访客界面更新拒绝绑定到其他记录的页面图标', function () {
    Storage::fake('public');

    $otherChannel = Channel::factory()->create([
    ]);
    $foreignAttachment = createChannelTestAttachment([
        'attachable_type' => Channel::class,
        'attachable_id' => $otherChannel->id,
    ]);

    $channel = Channel::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.visitor-interface.update', ['channel' => $channel->id]), [
            'site_name' => '帮助中心',
            'subtitle' => '',
            'header_enabled' => '1',
            'icon_id' => $foreignAttachment->id,
            'visitor_identity_mode' => WebChannelVisitorIdentityMode::ActualReceptionist->value,
            'composer_placeholder' => '',
            'theme_color' => WebChannelThemePalette::DEFAULT,
            'home_mode_enabled' => '0',
        ])
        ->assertUnprocessable()
        ->assertJson([
            'message' => __('channel.messages.invalid_attachment'),
        ]);

    expect($foreignAttachment->fresh()->attachable_id)->toBe($otherChannel->id)
        ->and($channel->fresh()->settings->visitor_interface->icon_id)->toBeNull();
});

test('访客界面更新接受未绑定页面图标并绑定到频道', function () {
    Storage::fake('public');
    $attachment = createChannelTestAttachment();
    $serviceAvatar = createChannelTestAttachment(['purpose' => 'avatar']);

    $channel = Channel::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->put(route('admin.manage.channels.web.visitor-interface.update', ['channel' => $channel->id]), [
            'site_name' => '帮助中心',
            'subtitle' => '',
            'header_enabled' => '1',
            'icon_id' => $attachment->id,
            'visitor_identity_mode' => WebChannelVisitorIdentityMode::ActualReceptionist->value,
            'service_display_name' => '统一客服',
            'service_avatar_id' => $serviceAvatar->id,
            'composer_placeholder' => '',
            'theme_color' => WebChannelThemePalette::DEFAULT,
            'home_mode_enabled' => '0',
        ])
        ->assertRedirect();

    $visitorInterface = $channel->fresh()->settings->visitor_interface;

    expect($visitorInterface->icon_id)->toBe($attachment->id)
        ->and($visitorInterface->service_display_name)->toBeNull()
        ->and($visitorInterface->service_avatar_id)->toBeNull()
        ->and($attachment->fresh()->attachable_id)->toBe($channel->id)
        ->and($serviceAvatar->fresh()->attachable_id)->toBeNull();
});

test('所有者可以查看频道回收站和恢复频道', function () {
    $version = createDeployableReceptionPlanVersion($this->systemContext);

    $channel = Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
    ]);

    $channel->delete();

    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.trash'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/web/Trash')
            ->has('trashed_channel_list', 1)
            ->where('trashed_channel_list.0.id', (string) $channel->id)
        );

    $this->actingAs($this->user)
        ->from(route('admin.manage.channels.web.trash'))
        ->put(route('admin.manage.channels.web.restore', ['channel' => $channel->id]))
        ->assertRedirect();

    expect($channel->fresh()->deleted_at)->toBeNull();
});

test('频道代码会唯一生成', function () {
    Channel::factory()->count(2)->create([
    ]);

    [$first, $second] = Channel::query()->orderBy('created_at')->get()->all();

    expect($first->code)->not->toBe($second->code)
        ->and($first->code)->toStartWith('wch_')
        ->and($second->code)->toStartWith('wch_');
});

test('频道设置转换水合数据对象来自数组载荷', function () {
    $channel = Channel::factory()->create([
        'settings' => [
            'visitor_interface' => [
                'header' => [
                    'enabled' => false,
                ],
                'theme_color' => '#C2185B',
            ],
        ],
    ]);

    $channel->refresh();

    expect($channel->settings)->toBeInstanceOf(ChannelWebSettingsData::class)
        ->and($channel->settings->visitor_interface->theme_color)->toBe('#C2185B')
        ->and($channel->settings->visitor_interface->header?->enabled)->toBeFalse()
        ->and(WebChannelData::fromModel($channel)->visitor_interface->theme_color)->toBe('#C2185B');
});

test('非所有者用户不能访问或修改网页频道', function () {
    $admin = User::factory()->create();

    $operator = User::factory()->create();

    $channel = Channel::factory()->create([
    ]);

    $this->actingAs($admin)
        ->get(route('admin.manage.channels.web.index'))
        ->assertForbidden();

    $this->actingAs($operator)
        ->get(route('admin.manage.channels.web.show', ['channel' => $channel->id]))
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('admin.manage.channels.web.store'), [
            'name' => '管理员创建',
        ])
        ->assertForbidden();
});

test('单租户下管理员可以访问或删除任意频道', function () {
    $otherChannel = Channel::factory()->create([
    ]);

    $this->actingAs($this->user)
        ->get(route('admin.manage.channels.web.show', ['channel' => $otherChannel->id]))
        ->assertOk();

    $this->actingAs($this->user)
        ->delete(route('admin.manage.channels.web.destroy', ['channel' => $otherChannel->id]))
        ->assertRedirect();

    expect(Channel::query()->whereKey($otherChannel->id)->exists())->toBeFalse();
});
