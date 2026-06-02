<?php

use App\Data\Channel\Telegram\ChannelTelegramSettingsData;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\WithWorkspace;

require_once __DIR__.'/TelegramTestSupport.php';

uses(RefreshDatabase::class, WithWorkspace::class);

beforeEach(function () {
    $this->user = $this->createUserWithWorkspace();
    config(['app.url' => 'https://helmdesk.test']);

    $settings = app(GeneralSettings::class);
    $settings->base_url = 'https://helmdesk.test';
    $settings->save();
});

/**
 * 伪造 Telegram Bot API：getMe / setWebhook / deleteWebhook 全部成功。
 */
function fakeTelegramApiOk(): void
{
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 777000111, 'username' => 'helmdesk_bot', 'first_name' => 'HelmDesk']]),
        '*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        '*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
    ]);
}

test('所有者可以创建 Telegram 渠道并注册 webhook', function () {
    fakeTelegramApiOk();
    $version = createTelegramDeployablePlanVersion($this->workspace);

    $response = $this->actingAs($this->user)
        ->from(route('workspace.manage.channels.telegram.create'))
        ->post(route('workspace.manage.channels.telegram.store'), [
            'name' => '客服机器人',
            'bot_token' => '777000111:AAHk9_test_token_value_abcdefghijklmno',
            'description' => '官方 Telegram 客服',
            'reception_plan_id' => $version->reception_plan_id,
        ]);

    $channel = Channel::query()->firstOrFail();

    $response->assertRedirect(route('workspace.manage.channels.telegram.show', ['channel' => $channel->id,
    ]));

    $settings = $channel->settings;
    expect($channel->type)->toBe(ChannelType::Telegram)
        ->and($channel->name)->toBe('客服机器人')
        ->and($channel->code)->toStartWith('tg_')
        ->and($channel->telegram_bot_token)->toBe('777000111:AAHk9_test_token_value_abcdefghijklmno')
        ->and($settings)->toBeInstanceOf(ChannelTelegramSettingsData::class)
        ->and($settings->bot_username)->toBe('helmdesk_bot')
        ->and($settings->bot_id)->toBe(777000111)
        ->and($settings->webhook_secret)->not->toBe('');

    // bot_token 以密文落库（直接读列原始值不是明文）。
    $raw = DB::table('channels')->where('id', $channel->id)->value('telegram_bot_token');
    expect($raw)->not->toContain('777000111:AAHk9');

    // setWebhook 携带本渠道 code 的公网 URL 与 secret_token。
    Http::assertSent(function ($request) use ($channel, $settings) {
        return str_contains($request->url(), '/setWebhook')
            && $request['url'] === 'https://helmdesk.test/webhook/telegram/'.$channel->code
            && $request['secret_token'] === $settings->webhook_secret;
    });
});

test('Telegram 注册 webhook 使用后台保存的最新主机地址', function () {
    fakeTelegramApiOk();
    config(['app.url' => 'https://stale.helmdesk.test']);

    $settings = app(GeneralSettings::class);
    $settings->base_url = 'https://support.example.test';
    $settings->save();

    $version = createTelegramDeployablePlanVersion($this->workspace);

    $response = $this->actingAs($this->user)
        ->from(route('workspace.manage.channels.telegram.create'))
        ->post(route('workspace.manage.channels.telegram.store'), [
            'name' => '客服机器人',
            'bot_token' => '777000111:AAHk9_test_token_value_abcdefghijklmno',
            'reception_plan_id' => $version->reception_plan_id,
        ]);

    $channel = Channel::query()->firstOrFail();

    $response->assertRedirect(route('workspace.manage.channels.telegram.show', ['channel' => $channel->id,
    ]));

    Http::assertSent(function ($request) use ($channel) {
        return str_contains($request->url(), '/setWebhook')
            && $request['url'] === 'https://support.example.test/webhook/telegram/'.$channel->code;
    });
});

test('Telegram 渠道创建需要 Token 和方案版本', function () {
    createTelegramDeployablePlanVersion($this->workspace);

    $this->actingAs($this->user)
        ->from(route('workspace.manage.channels.telegram.create'))
        ->post(route('workspace.manage.channels.telegram.store'), [
            'name' => '客服机器人',
        ])
        ->assertSessionHasErrors(['bot_token', 'reception_plan_id']);
});

test('Telegram 拒绝非法 Token 时创建失败且不落库', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401),
    ]);
    $version = createTelegramDeployablePlanVersion($this->workspace);

    $this->actingAs($this->user)
        ->from(route('workspace.manage.channels.telegram.create'))
        ->post(route('workspace.manage.channels.telegram.store'), [
            'name' => '客服机器人',
            'bot_token' => '111:AAHk9_invalid_token_value_abcdefghijkl',
            'reception_plan_id' => $version->reception_plan_id,
        ]);

    expect(Channel::query()->count())->toBe(0);
});

test('webhook 注册失败时回滚已创建的渠道', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'b', 'first_name' => 'B']]),
        '*/setWebhook' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad webhook: HTTPS url must be provided'], 400),
    ]);
    $version = createTelegramDeployablePlanVersion($this->workspace);

    $this->actingAs($this->user)
        ->from(route('workspace.manage.channels.telegram.create'))
        ->post(route('workspace.manage.channels.telegram.store'), [
            'name' => '客服机器人',
            'bot_token' => '111:AAHk9_valid_format_token_abcdefghijklmn',
            'reception_plan_id' => $version->reception_plan_id,
        ]);

    expect(Channel::query()->count())->toBe(0);
});

test('列表页与详情页正常渲染', function () {
    fakeTelegramApiOk();
    $version = createTelegramDeployablePlanVersion($this->workspace);
    $channel = Channel::factory()->telegram()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'name' => '官方客服 Bot',
    ]);

    $this->actingAs($this->user)
        ->get(route('workspace.manage.channels.telegram.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/telegram/List')
            ->has('channel_list', 1)
            ->where('channel_list.0.name', '官方客服 Bot')
            ->where('channel_list.0.webhook_active', true)
        );

    $this->actingAs($this->user)
        ->get(route('workspace.manage.channels.telegram.show', ['channel' => $channel->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/telegram/Show')
            ->where('telegram_channel.id', (string) $channel->id)
            ->where('telegram_channel.webhook_url', 'https://helmdesk.test/webhook/telegram/'.$channel->code)
            ->has('form_options.reception_plan_options')
        );
});

test('创建页正常渲染', function () {
    createTelegramDeployablePlanVersion($this->workspace);

    $this->actingAs($this->user)
        ->get(route('workspace.manage.channels.telegram.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/telegram/Create')
            ->has('reception_plan_options')
        );
});

test('所有者可以软删除 Telegram 渠道并撤销 webhook', function () {
    fakeTelegramApiOk();
    $version = createTelegramDeployablePlanVersion($this->workspace);
    $channel = Channel::factory()->telegram()->create([
        'reception_plan_id' => $version->reception_plan_id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('workspace.manage.channels.telegram.destroy', ['channel' => $channel->id,
        ]))
        ->assertRedirect(route('workspace.manage.channels.telegram.index'));

    expect($channel->fresh()->trashed())->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/deleteWebhook'));
});

test('Telegram 撤销 webhook 失败仍可删除渠道', function () {
    Http::fake([
        '*/deleteWebhook' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401),
    ]);
    $version = createTelegramDeployablePlanVersion($this->workspace);
    $channel = Channel::factory()->telegram()->create([
        'reception_plan_id' => $version->reception_plan_id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('workspace.manage.channels.telegram.destroy', ['channel' => $channel->id,
        ]))
        ->assertRedirect(route('workspace.manage.channels.telegram.index'));

    expect($channel->fresh()->trashed())->toBeTrue();
});

test('回收站列出已删除的 Telegram 渠道', function () {
    fakeTelegramApiOk();
    $version = createTelegramDeployablePlanVersion($this->workspace);
    $channel = Channel::factory()->telegram()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'name' => '待恢复 Bot',
    ]);
    $channel->delete();

    $this->actingAs($this->user)
        ->get(route('workspace.manage.channels.telegram.trash'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel/telegram/Trash')
            ->has('trashed_channel_list', 1)
            ->where('trashed_channel_list.0.id', (string) $channel->id)
            ->where('trashed_channel_list.0.name', '待恢复 Bot')
        );
});

test('从回收站恢复 Telegram 渠道并重注册 webhook', function () {
    fakeTelegramApiOk();
    $version = createTelegramDeployablePlanVersion($this->workspace);
    $channel = Channel::factory()->telegram()->create([
        'reception_plan_id' => $version->reception_plan_id,
    ]);
    $channel->delete();

    $this->actingAs($this->user)
        ->put(route('workspace.manage.channels.telegram.restore', ['channel' => $channel->id,
        ]))
        ->assertRedirect(route('workspace.manage.channels.telegram.index'));

    expect($channel->fresh()->trashed())->toBeFalse();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/setWebhook'));
});
