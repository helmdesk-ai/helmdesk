<?php

namespace App\Actions\Contact;

use App\Enums\ContactSource;
use App\Enums\ContactType;
use App\Enums\IdentityType;
use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\ContactIdentity;
use App\Models\Workspace;
use App\Services\Contact\ContactActivityLogger;
use App\Services\Contact\ContactIdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 按身份标识查找或创建联系人，用于渠道访客归属。
 */
class ResolveContactIdentityAction
{
    use AsAction;

    /**
     * @param  array{type: IdentityType, value: string, namespace?: string}  $identityData
     */
    public function handle(
        Workspace $workspace,
        array $identityData,
        ContactSource $source = ContactSource::Web,
        ?string $name = null,
    ): Contact {
        $type = $identityData['type'];

        if ($type === IdentityType::Phone && ! ContactIdentityNormalizer::isPhoneInputFormatValid($identityData['value'])) {
            throw ValidationException::withMessages([
                'value' => __('contact.invalid_phone'),
            ]);
        }

        $value = ContactIdentityNormalizer::normalizeValue($type, $identityData['value']);
        $namespace = $identityData['namespace'] ?? '';

        if ($type === IdentityType::Phone && ! ContactIdentityNormalizer::isNormalizedPhoneValid($value)) {
            throw ValidationException::withMessages([
                'value' => __('contact.invalid_phone'),
            ]);
        }

        if ($type->requiresNamespace() && $namespace === '') {
            throw ValidationException::withMessages([
                'namespace' => __('contact.namespace_required_for_external_id'),
            ]);
        }

        $shouldPromote = ContactIdentityNormalizer::promotesContactType($type);

        return DB::transaction(function () use ($type, $value, $namespace, $source, $name, $shouldPromote) {
            $existing = ContactIdentity::query()
                ->where('type', $type)
                ->where('namespace', $namespace)
                ->where('value', $value)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $contact = Contact::query()->findOrFail($existing->contact_id);

                if ($shouldPromote && $contact->type === ContactType::Visitor) {
                    $previousType = $contact->type;
                    $contact->type = ContactType::Contact;
                    $contact->saveQuietly();
                    ContactActivityLogger::record(
                        $contact,
                        ContactActivityLog::ACTION_UPDATED,
                        payload: [
                            'origin' => 'resolve_identity',
                            'field_changes' => [
                                'type' => [
                                    'old' => $previousType->value,
                                    'new' => $contact->type->value,
                                ],
                            ],
                        ],
                    );
                }

                return $contact;
            }

            $contact = Contact::query()->create([
                'type' => $shouldPromote ? ContactType::Contact : ContactType::Visitor,
                'source' => $source,
                'name' => $name,
                'avatar_url' => Contact::DEFAULT_AVATAR_URL,
            ]);

            ContactIdentity::query()->create([
                'contact_id' => $contact->id,
                'type' => $type,
                'namespace' => $namespace,
                'value' => $value,
                'display_value' => ContactIdentityNormalizer::buildDisplayValue($type, $value),
            ]);

            $contact->syncPrimaryFields();
            ContactActivityLogger::record(
                $contact,
                ContactActivityLog::ACTION_CREATED,
                payload: [
                    'origin' => 'resolve_identity',
                    'name' => $contact->name,
                    'source' => $contact->source->value,
                    'type' => $contact->type->value,
                    'identity_type' => $type->value,
                    'identity_value' => $value,
                    'identity_values' => [$value],
                ],
            );

            return $contact;
        });
    }
}
