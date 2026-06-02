<?php

use App\Actions\Reception\StartOrResumeReceptionSessionAction;
use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Enums\ContactType;
use App\Enums\ConversationEntryMode;
use App\Enums\IdentityType;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\SystemContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.go_runtime.base_url' => 'http://go-runtime.test',
        'services.go_runtime.bridge_token' => 'bridge-token',
    ]);

    Http::fake([
        'http://go-runtime.test/_helmdesk/internal/realtime/publish' => Http::response(['success' => true]),
    ]);
});

function signedChannelFor(string $secret = 'test-secret-supersecret-xxxxxxxxxx'): Channel
{
    $systemContext = SystemContext::factory()->create();
    $provider = AiProvider::query()->create([
        'brand' => 'custom-openai',
        'slug' => 'signed-token-provider-'.Str::lower(Str::random(6)),
        'name' => 'Signed Token Provider',
        'protocol' => 'openai',
        'credentials' => ['api_key' => 'test'],
        'credential_fields' => [['field' => 'api_key', 'label' => 'API', 'required' => true, 'secret' => true]],
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $model = AiModel::query()->create([
        'ai_provider_id' => $provider->id,
        'name' => 'Signed Token Model',
        'model_id' => 'gpt-signed-token',
        'type' => 'llm',
        'is_active' => true,
        'is_builtin' => false,
        'sort_order' => 0,
    ]);
    $plan = ReceptionPlan::factory()->create([
        'name' => '签名接待方案-'.Str::lower(Str::random(6)),
    ]);
    $version = ReceptionPlanVersion::factory()
        ->for($plan, 'plan')
        ->withReceptionModel($model->id)
        ->create();

    return Channel::factory()->create([
        'reception_plan_id' => $version->reception_plan_id,
        'reception_plan_version_id' => $version->id,
        'settings' => ChannelWebSettingsData::defaults([
            'user_token_secret' => $secret,
        ]),
    ]);
}

function makeSignedUserToken(string $secret, array $claims): string
{
    $base64Url = static fn (string $input): string => rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $headerB64 = $base64Url(json_encode($header));
    $payloadB64 = $base64Url(json_encode($claims));
    $signature = hash_hmac('sha256', $headerB64.'.'.$payloadB64, $secret, true);

    return $headerB64.'.'.$payloadB64.'.'.$base64Url($signature);
}

test('签名 user_token 通过时联系人按 external_id 解析并写入展示名', function () {
    $channel = signedChannelFor();
    $secret = $channel->settings->user_token_secret;
    $token = makeSignedUserToken($secret, [
        'sub' => 'crm:user_42',
        'name' => '黎博士',
        'email' => 'Li@Example.com',
        'iat' => time() - 5,
        'exp' => time() + 3600,
    ]);

    $started = app(StartOrResumeReceptionSessionAction::class)->handle(
        $channel->code,
        null,
        ConversationEntryMode::Standalone,
        null,
        $token,
    );

    $contact = Contact::query()->firstOrFail();
    $externalIdentity = ContactIdentity::query()
        ->where('contact_id', $contact->id)
        ->where('type', IdentityType::ExternalId)
        ->first();
    $emailIdentity = ContactIdentity::query()
        ->where('contact_id', $contact->id)
        ->where('type', IdentityType::Email)
        ->first();

    expect($started->session_token)->not->toBeEmpty()
        ->and($contact->type)->toBe(ContactType::Contact)
        ->and($contact->name)->toBe('黎博士')
        ->and($externalIdentity?->value)->toBe('crm:user_42')
        ->and($externalIdentity?->namespace)->toBe('web:'.$channel->code)
        ->and($emailIdentity?->value)->toBe('li@example.com')
        ->and($contact->fresh()->primary_email)->toBe('li@example.com');
});

test('同一签名访客多次进入时复用同一联系人', function () {
    $channel = signedChannelFor();
    $secret = $channel->settings->user_token_secret;
    $token = makeSignedUserToken($secret, ['sub' => 'crm:user_42', 'iat' => time() - 5, 'exp' => time() + 3600]);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone, null, $token);
    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone, null, $token);

    expect(Contact::query()->count())->toBe(1)
        ->and(ContactIdentity::query()->where('type', IdentityType::ExternalId)->count())->toBe(1);
});

test('签名错误时使用 session 身份', function () {
    $channel = signedChannelFor();
    $bogusToken = makeSignedUserToken('different-secret-xxxxxxxxxxxxxxx', ['sub' => 'crm:user_42', 'exp' => time() + 3600]);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone, null, $bogusToken);

    expect(ContactIdentity::query()->where('type', IdentityType::ExternalId)->count())->toBe(0)
        ->and(ContactIdentity::query()->where('type', IdentityType::Session)->count())->toBe(1)
        ->and(Contact::query()->firstOrFail()->type)->toBe(ContactType::Visitor);
});

test('签名过期时使用 session 身份', function () {
    $channel = signedChannelFor();
    $token = makeSignedUserToken($channel->settings->user_token_secret, ['sub' => 'crm:user_42', 'exp' => time() - 3600]);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone, null, $token);

    expect(ContactIdentity::query()->where('type', IdentityType::ExternalId)->count())->toBe(0)
        ->and(ContactIdentity::query()->where('type', IdentityType::Session)->count())->toBe(1);
});

test('未配置 user_token_secret 时即便传 token 也忽略', function () {
    $channel = signedChannelFor('');
    $token = makeSignedUserToken('any-secret', ['sub' => 'crm:user_42', 'exp' => time() + 3600]);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone, null, $token);

    expect(ContactIdentity::query()->where('type', IdentityType::ExternalId)->count())->toBe(0);
});

test('签名 token 邮箱被另一联系人占用时不强行抢占', function () {
    $channel = signedChannelFor();
    $secret = $channel->settings->user_token_secret;

    $otherContact = Contact::factory()->create([]);
    ContactIdentity::query()->create([
        'contact_id' => $otherContact->id,
        'type' => IdentityType::Email,
        'namespace' => '',
        'value' => 'occupied@example.com',
        'display_value' => 'occupied@example.com',
    ]);

    $token = makeSignedUserToken($secret, [
        'sub' => 'crm:user_99',
        'email' => 'occupied@example.com',
        'exp' => time() + 3600,
    ]);

    app(StartOrResumeReceptionSessionAction::class)->handle($channel->code, null, ConversationEntryMode::Standalone, null, $token);

    $signedContact = Contact::query()
        ->whereHas('identities', fn ($query) => $query->where('type', IdentityType::ExternalId)->where('value', 'crm:user_99'))
        ->firstOrFail();

    $emailIdentitiesOnSignedContact = ContactIdentity::query()
        ->where('contact_id', $signedContact->id)
        ->where('type', IdentityType::Email)
        ->count();

    expect($emailIdentitiesOnSignedContact)->toBe(0)
        ->and($signedContact->primary_email)->toBeNull();
});
