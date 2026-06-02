<?php

use App\Actions\Translation\CheckTranslationProviderAction;
use App\Actions\Translation\ClearTranslationProviderCredentialsAction;
use App\Actions\Translation\CreateTranslationProviderAction;
use App\Actions\Translation\DeleteTranslationProviderAction;
use App\Actions\Translation\ShowSystemTranslationProvidersAction;
use App\Actions\Translation\UpdateTranslationProviderCredentialsAction;
use App\Data\Translation\FormCheckTranslationProviderData;
use App\Data\Translation\FormCreateTranslationProviderData;
use App\Data\Translation\FormUpdateTranslationProviderData;
use App\Enums\TranslationProviderType;
use App\Exceptions\BusinessException;
use App\Models\ReceptionPlan;
use App\Models\TranslationProvider;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\WithSystemContext;

uses(RefreshDatabase::class, WithSystemContext::class);

beforeEach(function () {
    $this->createUserWithSystem();
});

// ---------------------------------------------------------------------------
// Show 直接调用：组装 props 形状正确
// ---------------------------------------------------------------------------

it('ShowSystemTranslationProvidersAction 返回系统下的 providers + 协议下拉', function () {
    TranslationProvider::factory()->create([
        'slug' => 'p-a',
        'sort_order' => 2,
    ]);
    TranslationProvider::factory()->create([
        'slug' => 'p-b',
        'sort_order' => 1,
    ]);

    $props = ShowSystemTranslationProvidersAction::run($this->systemContext);

    expect($props->providers)->toHaveCount(2)
        // 按 sort_order 升序：p-b（1）在前
        ->and($props->providers[0]->slug)->toBe('p-b')
        ->and($props->providers[1]->slug)->toBe('p-a')
        // protocolOptions 由 EnumOptionData::fromCases 生成
        ->and($props->protocolOptions)->toHaveCount(count(TranslationProviderType::cases()))
        ->and($props->protocolOptions[0]->value)->toBe(TranslationProviderType::GoogleTranslate->value)
        ->and($props->protocolCredentialFields[TranslationProviderType::GoogleTranslate->value][0]['field'])->toBe('api_key');
});

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

it('CreateTranslationProviderAction 创建待配置的自定义 provider', function () {
    $provider = CreateTranslationProviderAction::run(
        $this->systemContext,
        FormCreateTranslationProviderData::from([
            'name' => '我的 Google',
            'protocol' => TranslationProviderType::GoogleTranslate->value,
            'configuration' => ['api_key' => 'created-key'],
        ]),
    );

    expect($provider->name)->toBe('我的 Google')
        ->and($provider->is_builtin)->toBeFalse()
        ->and($provider->slug)->toStartWith('google-')
        ->and($provider->credentials)->toBe(['api_key' => 'created-key']);
});

// ---------------------------------------------------------------------------
// Update credentials
// ---------------------------------------------------------------------------

it('UpdateTranslationProviderCredentialsAction 合并新值', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => ['api_key' => 'old'],
    ]);

    UpdateTranslationProviderCredentialsAction::run(
        $this->systemContext,
        $provider->slug,
        FormUpdateTranslationProviderData::from([
            'name' => 'Renamed Google',
            'configuration' => ['api_key' => 'new'],
        ]),
    );

    expect($provider->fresh()->credentials)->toBe(['api_key' => 'new'])
        ->and($provider->fresh()->name)->toBe('Renamed Google');
});

it('UpdateTranslationProviderCredentialsAction 提交空 secret 字段保留旧值', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => ['api_key' => 'keep'],
    ]);

    UpdateTranslationProviderCredentialsAction::run(
        $this->systemContext,
        $provider->slug,
        FormUpdateTranslationProviderData::from([
            'name' => $provider->name,
            'configuration' => ['api_key' => ''],
        ]),
    );

    expect($provider->fresh()->credentials)->toBe(['api_key' => 'keep']);
});

// ---------------------------------------------------------------------------
// Clear credentials
// ---------------------------------------------------------------------------

it('ClearTranslationProviderCredentialsAction 清空凭据', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => ['api_key' => 'x'],
    ]);

    ClearTranslationProviderCredentialsAction::run($this->systemContext, $provider->slug);

    expect($provider->fresh())->credentials->toBeNull()
        ->and($provider->fresh()->hasCompleteCredentials())->toBeFalse();
});

// ---------------------------------------------------------------------------
// hasCompleteCredentials
// ---------------------------------------------------------------------------

it('hasCompleteCredentials 必填凭据缺失时为 false', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => null,
    ]);

    expect($provider->hasCompleteCredentials())->toBeFalse();

    $provider->update(['credentials' => ['api_key' => 'k']]);

    expect($provider->fresh()->hasCompleteCredentials())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Delete
// ---------------------------------------------------------------------------

it('DeleteTranslationProviderAction 内置 provider 拒绝删除', function () {
    $provider = TranslationProvider::factory()->create([
        'is_builtin' => true,
    ]);

    expect(fn () => DeleteTranslationProviderAction::run($this->systemContext, $provider->slug))
        ->toThrow(BusinessException::class);

    expect(TranslationProvider::find($provider->id))->not->toBeNull();
});

it('DeleteTranslationProviderAction 被接待方案引用时拒绝删除', function () {
    $provider = TranslationProvider::factory()->create([
        'is_builtin' => false,
        'credentials' => ['api_key' => 'k'],
    ]);

    ReceptionPlan::factory()->create([
        'translation_config' => [
            'enabled' => true,
            'failure_mode' => 'skip',
            'provider_id' => $provider->id,
        ],
    ]);

    expect(fn () => DeleteTranslationProviderAction::run($this->systemContext, $provider->slug))
        ->toThrow(BusinessException::class);

    expect(TranslationProvider::find($provider->id))->not->toBeNull();
});

it('DeleteTranslationProviderAction 未被引用时正常删除', function () {
    $provider = TranslationProvider::factory()->create([
        'is_builtin' => false,
    ]);

    DeleteTranslationProviderAction::run($this->systemContext, $provider->slug);

    expect(TranslationProvider::find($provider->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Check
// ---------------------------------------------------------------------------

it('CheckTranslationProviderAction 成功路径返回 success=true + result', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => ['api_key' => 'k'],
    ]);

    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    ['translatedText' => '你好', 'detectedSourceLanguage' => 'en'],
                ],
            ],
        ]),
    ]);

    $result = CheckTranslationProviderAction::run(
        $this->systemContext,
        $provider->slug,
        FormCheckTranslationProviderData::from([
            'text' => 'Hello',
            'target_lang' => 'zh-CN',
        ]),
    );

    expect($result->success)->toBeTrue()
        ->and($result->result)->not->toBeNull()
        ->and($result->result->text)->toBe('你好')
        ->and($result->result->source_lang)->toBe('en');
});

it('CheckTranslationProviderAction 抓住 driver 异常并降级为 success=false', function () {
    $provider = TranslationProvider::factory()->create([
        'credentials' => ['api_key' => 'k'],
    ]);

    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'error' => ['message' => 'API key not valid'],
        ], 400),
    ]);

    $result = CheckTranslationProviderAction::run(
        $this->systemContext,
        $provider->slug,
        FormCheckTranslationProviderData::from([
            'text' => 'Hello',
            'target_lang' => 'zh-CN',
        ]),
    );

    expect($result->success)->toBeFalse()
        ->and($result->result)->toBeNull()
        ->and($result->message)->toContain('API key not valid');
});

// ---------------------------------------------------------------------------
// 404 / 跨系统隔离
// ---------------------------------------------------------------------------

it('Action 在 provider slug 不存在时抛 ModelNotFoundException', function () {
    expect(fn () => UpdateTranslationProviderCredentialsAction::run(
        $this->systemContext,
        'does-not-exist',
        FormUpdateTranslationProviderData::from([
            'name' => 'Missing',
            'configuration' => ['api_key' => 'x'],
        ]),
    ))->toThrow(ModelNotFoundException::class);
});
