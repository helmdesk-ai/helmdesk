<?php

namespace App\Actions\CustomAttribute;

use App\Data\CustomAttribute\FormUpdateContactAttributeValuesData;
use App\Data\SystemUserContextData;
use App\Enums\AttributeType;
use App\Enums\AttributeValueSource;
use App\Models\AttributeDefinition;
use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\ContactAttributeValue;
use App\Models\SystemContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 批量更新联系人上的自定义属性值。
 */
class UpdateContactAttributeValuesAction
{
    use AsAction;

    public function handle(SystemContext $systemContext, string $contactId, array $attributes, int|string|null $userId = null): void
    {
        $contact = Contact::query()
            ->findOrFail($contactId);

        $definitions = $systemContext->attributeDefinitions()
            ->withTrashed()
            ->get()
            ->keyBy('key');

        $existingValues = ContactAttributeValue::query()
            ->where('contact_id', $contact->id)
            ->get()
            ->keyBy('definition_id');

        $changed = [];

        DB::transaction(function () use ($contact, $attributes, $definitions, $existingValues, $userId, &$changed) {
            foreach ($attributes as $key => $rawValue) {
                $definition = $definitions->get($key);

                if (! $definition) {
                    continue;
                }

                if ($definition->trashed()) {
                    throw ValidationException::withMessages([
                        "attributes.{$key}" => __('custom_attribute.attribute_archived'),
                    ]);
                }

                $normalizedValue = $this->normalizeValue($definition, $rawValue);
                $isEmpty = $this->isEmptyValue($definition, $normalizedValue);
                $existing = $existingValues->get($definition->id);
                $oldValue = $existing?->value();

                if ($isEmpty) {
                    if ($existing) {
                        $changed[] = ['key' => $key, 'old' => $oldValue, 'new' => null];
                        $existing->delete();
                    }
                } else {
                    $this->validateValue($definition, $normalizedValue);
                    $valueJson = ['value' => $normalizedValue];

                    if ($existing) {
                        if ($oldValue !== $normalizedValue) {
                            $changed[] = ['key' => $key, 'old' => $oldValue, 'new' => $normalizedValue];
                        }
                        $existing->update([
                            'value_json' => $valueJson,
                            'source' => AttributeValueSource::Manual,
                            'updated_by_user_id' => $userId,
                        ]);
                    } else {
                        $changed[] = ['key' => $key, 'old' => null, 'new' => $normalizedValue];
                        ContactAttributeValue::query()->create([
                            'contact_id' => $contact->id,
                            'definition_id' => $definition->id,
                            'value_json' => $valueJson,
                            'source' => AttributeValueSource::Manual,
                            'updated_by_user_id' => $userId,
                        ]);
                    }
                }
            }

            if (! empty($changed)) {
                ContactActivityLog::query()->create([
                    'contact_id' => $contact->id,
                    'actor_user_id' => $userId,
                    'action' => 'custom_attributes_updated',
                    'payload' => ['changed' => $changed],
                ]);
            }
        });
    }

    public function asController(Request $request, string $id): Response
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $systemContext = $ctx->systemContext();
        $data = FormUpdateContactAttributeValuesData::from($request);

        $this->handle($systemContext, $id, $data->attributes, $request->user()?->id);

        return back();
    }

    private function normalizeValue(AttributeDefinition $definition, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($definition->type) {
            AttributeType::Number => is_numeric($value) ? $value + 0 : $value,
            AttributeType::Boolean => is_bool($value) ? $value : ($value === 'true' ? true : ($value === 'false' ? false : $value)),
            AttributeType::MultiSelect => is_array($value) ? array_values(array_unique($value)) : $value,
            default => $value,
        };
    }

    private function isEmptyValue(AttributeDefinition $definition, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if ($definition->type === AttributeType::MultiSelect && is_array($value) && empty($value)) {
            return true;
        }

        return false;
    }

    private function validateValue(AttributeDefinition $definition, mixed $value): void
    {
        $valid = match ($definition->type) {
            AttributeType::Text, AttributeType::Textarea => is_string($value),
            AttributeType::Number => is_numeric($value),
            AttributeType::Date => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value),
            AttributeType::Boolean => is_bool($value),
            AttributeType::SingleSelect => $this->isValidOptionCode($definition, $value),
            AttributeType::MultiSelect => is_array($value) && $this->areValidOptionCodes($definition, $value),
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                "attributes.{$definition->key}" => __('custom_attribute.invalid_attribute_value', ['name' => $definition->name]),
            ]);
        }
    }

    private function isValidOptionCode(AttributeDefinition $definition, mixed $code): bool
    {
        if (! is_string($code)) {
            return false;
        }

        $options = $definition->config['options'] ?? [];

        return collect($options)->pluck('code')->contains($code);
    }

    /**
     * @param  array<int, mixed>  $codes
     */
    private function areValidOptionCodes(AttributeDefinition $definition, array $codes): bool
    {
        foreach ($codes as $code) {
            if (! $this->isValidOptionCode($definition, $code)) {
                return false;
            }
        }

        return true;
    }
}
