<?php

namespace App\Actions\Reception;

use App\Actions\Channel\Web\ApplyVisitorQueryParamsAction;
use App\Actions\Contact\ResolveContactIdentityAction;
use App\Enums\ChannelType;
use App\Enums\ContactSource;
use App\Enums\ConversationEntryMode;
use App\Enums\IdentityType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Services\Channel\WebChannelUserTokenVerifier;
use App\Services\Contact\ContactIdentityNormalizer;
use App\Services\Reception\ReceptionSession;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 解析网站渠道访客接待上下文，负责渠道、签名/会话身份、联系人，并把会话生命周期决策委托给
 * FindOrCreateReceptionConversationAction（与渠道无关的建会话/收件箱状态/AI 接管逻辑）。
 */
class ResolveReceptionContextAction
{
    use AsAction;

    /**
     * 注入联系人身份解析、签名访客身份校验、查询参数写入与共享会话解析服务。
     */
    public function __construct(
        private readonly ResolveContactIdentityAction $resolveContactIdentityAction,
        private readonly WebChannelUserTokenVerifier $userTokenVerifier,
        private readonly ApplyVisitorQueryParamsAction $applyVisitorQueryParamsAction,
        private readonly FindOrCreateReceptionConversationAction $findOrCreateReceptionConversationAction,
        private readonly CaptureWebConversationContextAction $captureWebConversationContextAction,
    ) {}

    /**
     * 解析访客接待上下文并返回渠道、联系人、会话和会话 token。
     *
     * 当 $userToken 通过当前渠道的 user_token_secret 验签时，访客身份会按 token.sub 解析为
     * ExternalId 联系人，namespace 固定为 "web:{channel_code}"；token 中的 name/email 会就近写入联系人。
     *
     * $queryParams 用于渠道侧自动写入（URL/widget 参数 → 联系人字段/属性/标签），具体由
     * ChannelWebSettingsData.query_param_mappings 配置；SignedOnly 映射仅在 user_token 校验通过时生效。
     *
     * $visitorClient 承载 Go 边缘透传的 Web 访客信号（current_url/referrer/user_agent/ip_address 等），
     * 落到会话渠道上下文快照与浏览轨迹；与 $visitorEnvironment（写联系人的 locale/时区/地区）区分。
     *
     * @param  array{locale?: string|null, timezone?: string|null, country?: string|null, city?: string|null}|null  $visitorEnvironment
     * @param  array<string, string>|null  $queryParams
     * @param  array<string, mixed>|null  $visitorClient
     * @return array{channel: Channel, contact: Contact, conversation: Conversation, session_token: string, created: bool, signed_identity: array{external_id: string, name: ?string, email: ?string, claims: array<string, mixed>}|null}
     */
    public function handle(
        string $channelCode,
        ?string $sessionToken,
        ?ConversationEntryMode $entryMode = null,
        ?array $visitorEnvironment = null,
        ?string $userToken = null,
        ?array $queryParams = null,
        ?array $visitorClient = null,
    ): array {
        $entryMode ??= ConversationEntryMode::Standalone;

        $channel = $this->findActiveChannel($channelCode);

        $token = ReceptionSession::normalize($sessionToken) ?? ReceptionSession::generate();

        $signedIdentity = $this->userTokenVerifier->verify($channel, $userToken);
        $contact = $signedIdentity !== null
            ? $this->resolveSignedContact($channel, $signedIdentity)
            : $this->resolveContactIdentityAction->handle(
                $channel->workspace,
                ['type' => IdentityType::Session, 'value' => $token],
                ContactSource::Web,
            );

        $normalizedQueryParams = $this->normalizeQueryParams($queryParams);

        $this->touchContactVisit($contact, $visitorEnvironment);
        $this->applyVisitorQueryParamsAction->handle(
            $channel,
            $contact,
            $normalizedQueryParams,
            $signedIdentity !== null,
        );

        [$conversation, $created] = $this->findOrCreateReceptionConversationAction->handle(
            $channel,
            $contact,
            $entryMode,
            $channel->settings->default_visitor_locale->value,
        );

        if ($channel->type === ChannelType::Web && $visitorClient !== null) {
            $this->captureWebConversationContextAction->handle(
                $conversation,
                $visitorClient,
                $normalizedQueryParams,
                $created,
            );
        }

        return [
            'channel' => $channel,
            'contact' => $contact->fresh() ?? $contact,
            'conversation' => $conversation,
            'session_token' => $token,
            'created' => $created,
            'signed_identity' => $signedIdentity,
        ];
    }

    /**
     * 清洗 Go bridge 透传的 query 参数：丢弃非字符串键、空值、过长值。
     *
     * @param  array<string, mixed>|null  $queryParams
     * @return array<string, string>
     */
    private function normalizeQueryParams(?array $queryParams): array
    {
        if ($queryParams === null) {
            return [];
        }

        $normalized = [];
        foreach ($queryParams as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            $normalized[$key] = $trimmed;
        }

        return $normalized;
    }

    /**
     * 根据签名 token 解析联系人，并按需补齐 name / email identity。
     *
     * @param  array{external_id: string, name: ?string, email: ?string, claims: array<string, mixed>}  $signedIdentity
     */
    private function resolveSignedContact(Channel $channel, array $signedIdentity): Contact
    {
        $namespace = $this->userTokenVerifier->identityNamespace($channel);

        $contact = $this->resolveContactIdentityAction->handle(
            $channel->workspace,
            [
                'type' => IdentityType::ExternalId,
                'value' => $signedIdentity['external_id'],
                'namespace' => $namespace,
            ],
            ContactSource::Web,
            name: $signedIdentity['name'],
        );

        // 当前联系人未设置展示名时，补齐 token 携带的展示名。
        if ($signedIdentity['name'] !== null && ! filled($contact->name)) {
            $contact->forceFill(['name' => $signedIdentity['name']])->saveQuietly();
        }

        if ($signedIdentity['email'] !== null) {
            $this->attachEmailIdentityIfMissing($channel, $contact, $signedIdentity['email']);
        }

        return $contact;
    }

    /**
     * 把 token 携带的邮箱挂到联系人上：仅在邮箱在本工作区未被占用、且联系人尚无该邮箱时追加。
     * 冲突时不写入，保留给客服显式合并。
     */
    private function attachEmailIdentityIfMissing(Channel $channel, Contact $contact, string $email): void
    {
        $value = ContactIdentityNormalizer::normalizeValue(IdentityType::Email, $email);
        if ($value === '') {
            return;
        }

        $alreadyAttached = ContactIdentity::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('contact_id', $contact->id)
            ->where('type', IdentityType::Email)
            ->where('value', $value)
            ->exists();

        if ($alreadyAttached) {
            return;
        }

        $taken = ContactIdentity::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('type', IdentityType::Email)
            ->where('namespace', '')
            ->where('value', $value)
            ->exists();

        if ($taken) {
            return;
        }

        try {
            ContactIdentity::query()->create([
                'workspace_id' => $channel->workspace_id,
                'contact_id' => $contact->id,
                'type' => IdentityType::Email,
                'namespace' => '',
                'value' => $value,
                'display_value' => ContactIdentityNormalizer::buildDisplayValue(IdentityType::Email, $value),
            ]);
            $contact->syncPrimaryFields();
        } catch (UniqueConstraintViolationException) {
            Log::debug('访客邮箱身份写入遇到并发唯一约束。', [
                'workspace_id' => (string) $channel->workspace_id,
                'contact_id' => (string) $contact->id,
                'email' => $value,
            ]);
        }
    }

    /**
     * 查找可用于访客接待的网站渠道。
     *
     * AI 不可用时，访客可进入会话并排队待人工接待。
     *
     * 软删除（paused）渠道也会被返回，由会话解析逻辑区分处理：
     * 已有进行中会话允许继续消息往返，没有的话拒绝新建。
     */
    private function findActiveChannel(string $channelCode): Channel
    {
        $channel = Channel::query()
            ->withTrashed()
            ->where('code', $channelCode)
            ->where('type', ChannelType::Web)
            ->with(['receptionPlan', 'workspace'])
            ->first();

        if ($channel === null) {
            throw new NotFoundHttpException;
        }

        return $channel;
    }

    /**
     * 更新联系人最近访问时间和可识别的访客环境信息。
     *
     * @param  array{locale?: string|null, timezone?: string|null, country?: string|null, city?: string|null}|null  $visitorEnvironment
     */
    private function touchContactVisit(Contact $contact, ?array $visitorEnvironment): void
    {
        $updates = ['last_seen_at' => now()];

        $timezone = $this->normalizeTimezone($visitorEnvironment['timezone'] ?? null);
        if ($timezone !== null) {
            $updates['timezone'] = $timezone;
        }

        foreach (['country', 'city'] as $field) {
            $value = $this->normalizeText($visitorEnvironment[$field] ?? null, 120);
            if ($value !== null) {
                $updates[$field] = $value;
            }
        }

        $contact->forceFill($updates)->saveQuietly();
    }

    /**
     * 清理访客环境中的短文本字段。
     */
    private function normalizeText(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized) > $maxLength) {
            return null;
        }

        return $normalized;
    }

    /**
     * 校验并标准化访客时区。
     */
    private function normalizeTimezone(mixed $value): ?string
    {
        $timezone = $this->normalizeText($value, 80);
        if ($timezone === null) {
            return null;
        }

        if (in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        throw ValidationException::withMessages([
            'timezone' => __('validation.timezone', ['attribute' => 'timezone']),
        ]);
    }
}
