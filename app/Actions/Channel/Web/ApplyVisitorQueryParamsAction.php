<?php

namespace App\Actions\Channel\Web;

use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Data\Channel\Web\WebChannelQueryParamMappingData;
use App\Enums\AttributeType;
use App\Enums\AttributeValueSource;
use App\Enums\Channel\Web\WebChannelParamTarget;
use App\Enums\Channel\Web\WebChannelParamTrust;
use App\Enums\Channel\Web\WebChannelParamWriteMode;
use App\Enums\IdentityType;
use App\Enums\TagScope;
use App\Enums\TagSource;
use App\Models\AttributeDefinition;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactAttributeValue;
use App\Models\ContactIdentity;
use App\Models\Tag;
use App\Models\TagGroup;
use App\Services\Contact\ContactIdentityNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 把访客 URL / widget query 上的参数按渠道映射写回联系人。
 *
 * 设计原则：
 * - 这是接待入口的"轻量自动写入"，不抛业务异常：任何一条映射写不进去都跳过该映射，
 *   避免一个 URL 拼错导致访客无法发起会话。
 * - SignedOnly 信任级别只在 user_token 校验通过时才采纳；防止未登录访客在 URL 里乱填 email/phone。
 * - Attribute 必须 AttributeDefinition.is_api_writable=true 才允许写入。
 * - Tag 名称模板里的 {value} 占位会经过严格白名单（字母/数字/下划线/连字符，1~40 字符）；
 *   非法值不写入，避免攻击者通过 utm_source=<svg> 写出奇怪的标签。
 */
class ApplyVisitorQueryParamsAction
{
    use AsAction;

    /**
     * 单个参数值允许的最大长度，超过即视为非法。
     */
    private const MAX_VALUE_LENGTH = 1024;

    /**
     * Tag 模板 {value} 占位允许的字符白名单。
     */
    private const TAG_VALUE_PATTERN = '/^[a-zA-Z0-9_-]{1,40}$/';

    /**
     * AttributeDefinition 缓存的 TTL（秒）。
     */
    private const DEFINITIONS_CACHE_TTL = 300;

    /**
     * 把渠道配置里的 query_param_mappings 按 $queryParams 一次性 apply 到联系人上。
     *
     * @param  array<string, string>  $queryParams
     */
    public function handle(Channel $channel, Contact $contact, array $queryParams, bool $isSigned): void
    {
        if ($queryParams === []) {
            return;
        }

        $settings = $channel->settings instanceof ChannelWebSettingsData
            ? $channel->settings
            : ChannelWebSettingsData::defaults();
        $rawMappings = $settings->query_param_mappings;
        if ($rawMappings === []) {
            return;
        }

        $definitions = Cache::remember(
            'attribute_definitions:writable',
            self::DEFINITIONS_CACHE_TTL,
            fn (): Collection => AttributeDefinition::query()
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('key'),
        );

        foreach ($rawMappings as $rawMapping) {
            $mapping = $rawMapping instanceof WebChannelQueryParamMappingData
                ? $rawMapping
                : WebChannelQueryParamMappingData::from($rawMapping);

            $value = $this->normalizeValue($queryParams[$mapping->param_name] ?? null);
            if ($value === null) {
                continue;
            }
            if ($mapping->trust === WebChannelParamTrust::SignedOnly && ! $isSigned) {
                continue;
            }

            $this->applyMapping($channel, $contact, $mapping, $value, $definitions);
        }
    }

    /**
     * @param  Collection<string, AttributeDefinition>  $definitions
     */
    private function applyMapping(
        Channel $channel,
        Contact $contact,
        WebChannelQueryParamMappingData $mapping,
        string $value,
        Collection $definitions,
    ): void {
        match ($mapping->target) {
            WebChannelParamTarget::ContactName => $this->applyContactName($contact, $mapping, $value),
            WebChannelParamTarget::ContactEmail => $this->applyContactEmail($channel, $contact, $mapping, $value),
            WebChannelParamTarget::ContactPhone => $this->applyContactPhone($channel, $contact, $mapping, $value),
            WebChannelParamTarget::ContactExternalId => $this->applyContactExternalId($channel, $contact, $mapping, $value),
            WebChannelParamTarget::ContactImportance => $this->applyContactImportance($contact, $mapping, $value),
            WebChannelParamTarget::Attribute => $this->applyAttribute($contact, $mapping, $value, $definitions),
            WebChannelParamTarget::Tag => $this->applyTag($channel, $contact, $mapping, $value),
        };
    }

    private function applyContactName(Contact $contact, WebChannelQueryParamMappingData $mapping, string $value): void
    {
        if ($mapping->write_mode === WebChannelParamWriteMode::OnlyIfEmpty && filled($contact->name)) {
            return;
        }

        $contact->forceFill(['name' => $value])->saveQuietly();
    }

    private function applyContactEmail(Channel $channel, Contact $contact, WebChannelQueryParamMappingData $mapping, string $value): void
    {
        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $normalized = ContactIdentityNormalizer::normalizeValue(IdentityType::Email, $value);
        if ($normalized === '') {
            return;
        }

        $this->writeContactIdentity(
            $channel,
            $contact,
            $mapping,
            type: IdentityType::Email,
            namespace: '',
            value: $normalized,
        );
    }

    /**
     * 将渠道参数写入联系人重点客户标记。
     */
    private function applyContactImportance(Contact $contact, WebChannelQueryParamMappingData $mapping, string $value): void
    {
        $isImportant = $this->normalizeBooleanValue($value);
        if ($isImportant === null) {
            return;
        }

        if ($mapping->write_mode === WebChannelParamWriteMode::OnlyIfEmpty && $contact->is_important) {
            return;
        }

        if ($contact->is_important === $isImportant) {
            return;
        }

        $contact->forceFill($isImportant
            ? [
                'is_important' => true,
                'important_at' => now(),
                'important_by_user_id' => null,
                'important_source' => 'channel',
            ]
            : [
                'is_important' => false,
                'important_at' => null,
                'important_by_user_id' => null,
                'important_source' => null,
            ])->saveQuietly();
    }

    private function applyContactPhone(Channel $channel, Contact $contact, WebChannelQueryParamMappingData $mapping, string $value): void
    {
        if (! ContactIdentityNormalizer::isPhoneInputFormatValid($value)) {
            return;
        }
        $normalized = ContactIdentityNormalizer::normalizeValue(IdentityType::Phone, $value);
        if ($normalized === '' || ! ContactIdentityNormalizer::isNormalizedPhoneValid($normalized)) {
            return;
        }

        $this->writeContactIdentity(
            $channel,
            $contact,
            $mapping,
            type: IdentityType::Phone,
            namespace: '',
            value: $normalized,
        );
    }

    private function applyContactExternalId(Channel $channel, Contact $contact, WebChannelQueryParamMappingData $mapping, string $value): void
    {
        if (strlen($value) > 191) {
            return;
        }

        $this->writeContactIdentity(
            $channel,
            $contact,
            $mapping,
            type: IdentityType::ExternalId,
            namespace: 'web:'.$channel->code,
            value: $value,
        );
    }

    /**
     * 通用 ContactIdentity 写入：
     *  - OnlyIfEmpty + 联系人已有同 type identity → 不写入
     *  - Overwrite   + 联系人已有同 type identity（不同 value）→ 软删原有身份后写入新值
     *  - 同 type/value 已被其他联系人占用 → 不写入
     */
    private function writeContactIdentity(
        Channel $channel,
        Contact $contact,
        WebChannelQueryParamMappingData $mapping,
        IdentityType $type,
        string $namespace,
        string $value,
    ): void {
        $existingOnContact = ContactIdentity::query()
            ->where('contact_id', $contact->id)
            ->where('type', $type)
            ->where('namespace', $namespace)
            ->get();

        if ($existingOnContact->contains(fn (ContactIdentity $identity) => $identity->value === $value)) {
            return;
        }

        if ($mapping->write_mode === WebChannelParamWriteMode::OnlyIfEmpty && $existingOnContact->isNotEmpty()) {
            return;
        }

        $takenElsewhere = ContactIdentity::query()
            ->where('type', $type)
            ->where('namespace', $namespace)
            ->where('value', $value)
            ->where('contact_id', '!=', $contact->id)
            ->exists();
        if ($takenElsewhere) {
            return;
        }

        try {
            DB::transaction(function () use ($contact, $existingOnContact, $type, $namespace, $value, $mapping): void {
                if ($mapping->write_mode === WebChannelParamWriteMode::Overwrite) {
                    foreach ($existingOnContact as $identity) {
                        $identity->delete();
                    }
                }

                ContactIdentity::query()->create([
                    'contact_id' => $contact->id,
                    'type' => $type,
                    'namespace' => $namespace,
                    'value' => $value,
                    'display_value' => ContactIdentityNormalizer::buildDisplayValue($type, $value),
                ]);

                $contact->syncPrimaryFields();
            });
        } catch (UniqueConstraintViolationException) {
            Log::debug('访客传参联系人身份写入遇到并发唯一约束。', [
                'contact_id' => (string) $contact->id,
                'type' => $type->value,
                'namespace' => $namespace,
            ]);
        }
    }

    /**
     * @param  Collection<string, AttributeDefinition>  $definitions
     */
    private function applyAttribute(Contact $contact, WebChannelQueryParamMappingData $mapping, string $value, Collection $definitions): void
    {
        $key = $mapping->target_key;
        if (! is_string($key) || $key === '') {
            return;
        }

        /** @var AttributeDefinition|null $definition */
        $definition = $definitions->get($key);
        if ($definition === null || ! $definition->is_api_writable) {
            return;
        }
        if (! in_array($definition->type, [
            AttributeType::Text,
            AttributeType::Textarea,
            AttributeType::Number,
            AttributeType::Date,
            AttributeType::SingleSelect,
        ], true)) {
            return;
        }

        $normalized = $this->normalizeAttributeValue($definition, $value);
        if ($normalized === null) {
            return;
        }

        $existing = ContactAttributeValue::query()
            ->where('contact_id', $contact->id)
            ->where('definition_id', $definition->id)
            ->first();

        if ($mapping->write_mode === WebChannelParamWriteMode::OnlyIfEmpty && $existing !== null) {
            return;
        }

        $payload = ['value' => $normalized];

        if ($existing) {
            $existing->update([
                'value_json' => $payload,
                'source' => AttributeValueSource::Channel,
            ]);
        } else {
            try {
                ContactAttributeValue::query()->create([
                    'contact_id' => $contact->id,
                    'definition_id' => $definition->id,
                    'value_json' => $payload,
                    'source' => AttributeValueSource::Channel,
                ]);
            } catch (UniqueConstraintViolationException) {
                Log::debug('访客传参联系人属性写入遇到并发唯一约束。', [
                    'contact_id' => (string) $contact->id,
                    'definition_id' => (string) $definition->id,
                ]);
            }
        }
    }

    private function applyTag(Channel $channel, Contact $contact, WebChannelQueryParamMappingData $mapping, string $value): void
    {
        $template = $mapping->target_key;
        if (! is_string($template) || trim($template) === '') {
            return;
        }

        $resolved = $this->resolveTagName($template, $value);
        if ($resolved === null) {
            return;
        }

        $normalized = mb_strtolower($resolved);

        try {
            DB::transaction(function () use ($channel, $contact, $resolved, $normalized): void {
                $group = $this->resolveChannelTagGroup($channel);

                $tag = Tag::query()
                    ->where('tag_group_id', $group->id)
                    ->where('normalized_name', $normalized)
                    ->whereNull('deleted_at')
                    ->first();

                if ($tag === null) {
                    $tag = Tag::query()->create([
                        'tag_group_id' => $group->id,
                        'name' => $resolved,
                        'source' => TagSource::Channel,
                    ]);
                }

                $alreadyAssigned = DB::table('contact_tag_assignments')
                    ->where('tag_id', $tag->id)
                    ->where('contact_id', $contact->id)
                    ->exists();

                if ($alreadyAssigned) {
                    return;
                }

                DB::table('contact_tag_assignments')->insert([
                    'tag_id' => $tag->id,
                    'contact_id' => $contact->id,
                    'assigned_by_user_id' => null,
                    'source' => TagSource::Channel,
                    'created_at' => now(),
                ]);

                $contact->searchable();
            });
        } catch (UniqueConstraintViolationException) {
            Log::debug('访客传参联系人标签写入遇到并发唯一约束。', [
                'contact_id' => (string) $contact->id,
                'tag_name' => $resolved,
            ]);
        }
    }

    /**
     * 解析渠道自动标签的归属组：标签必属于一个组，渠道来源标签统一落到联系人维度的「渠道参数」系统组。
     */
    private function resolveChannelTagGroup(Channel $channel): TagGroup
    {
        $name = __('tag.default_groups.channel');

        return TagGroup::query()->firstOrCreate(
            [
                'normalized_name' => mb_strtolower($name),
            ],
            [
                'name' => $name,
                'scope' => TagScope::Contact,
            ],
        );
    }

    private function resolveTagName(string $template, string $value): ?string
    {
        if (! str_contains($template, '{value}')) {
            $name = trim($template);

            return $name === '' ? null : mb_substr($name, 0, 120);
        }

        if (! preg_match(self::TAG_VALUE_PATTERN, $value)) {
            return null;
        }

        $name = trim(str_replace('{value}', $value, $template));

        return $name === '' ? null : mb_substr($name, 0, 120);
    }

    /**
     * 将渠道参数中的布尔型文本转换为重点客户开关。
     */
    private function normalizeBooleanValue(string $value): ?bool
    {
        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'yes', 'y', 'on', 'important', 'vip' => true,
            '0', 'false', 'no', 'n', 'off' => false,
            default => null,
        };
    }

    private function normalizeAttributeValue(AttributeDefinition $definition, string $value): mixed
    {
        return match ($definition->type) {
            AttributeType::Text, AttributeType::Textarea => mb_substr($value, 0, 1024),
            AttributeType::Number => is_numeric($value) ? $value + 0 : null,
            AttributeType::Date => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null,
            AttributeType::SingleSelect => $this->isValidOptionCode($definition, $value) ? $value : null,
            default => null,
        };
    }

    private function isValidOptionCode(AttributeDefinition $definition, string $code): bool
    {
        $options = $definition->config['options'] ?? [];

        foreach ($options as $option) {
            if (isset($option['code']) && $option['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    private function normalizeValue(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $value = trim($raw);
        if ($value === '' || strlen($value) > self::MAX_VALUE_LENGTH) {
            return null;
        }

        return $value;
    }
}
