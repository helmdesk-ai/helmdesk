<?php

namespace App\Actions\Contact;

use App\Data\Contact\FormMergeContactsData;
use App\Enums\AttributeType;
use App\Enums\AttributeValueSource;
use App\Enums\ContactType;
use App\Enums\IdentityType;
use App\Models\AttributeDefinition;
use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\ContactAttributeValue;
use App\Models\ContactIdentity;
use App\Models\Tag;
use App\Models\User;
use App\Services\Contact\ContactActivityLogger;
use App\Services\Contact\ContactAiContext;
use App\Services\Contact\ContactIdentityNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 合并多个联系人，并迁移身份、标签、属性和会话关联。
 */
class MergeContactsAction
{
    use AsAction;

    public function handle(string $targetContactId, string $mergedContactId, ?User $actor = null): Contact
    {
        if ($targetContactId === $mergedContactId) {
            throw new InvalidArgumentException('Cannot merge a contact with itself.');
        }

        $target = Contact::query()
            ->findOrFail($targetContactId);

        $merged = Contact::query()
            ->findOrFail($mergedContactId);

        return DB::transaction(function () use ($target, $merged, $actor) {
            $mergedIdentities = $merged->identities()->get();
            $this->mergeAttributes($target, $merged, $mergedIdentities);

            $mergedCustomAttributes = $this->mergeCustomAttributes($target, $merged);

            $identitySnapshots = $mergedIdentities->map(fn (ContactIdentity $i) => [
                'id' => $i->id,
                'type' => $i->type->value,
                'value' => $i->value,
                'namespace' => $i->namespace,
            ])->all();

            $attributeSnapshot = [
                'name' => $merged->name,
                'source' => $merged->source->value,
                'type' => $merged->type->value,
                'locale' => $merged->locale,
                'timezone' => $merged->timezone,
                'country' => $merged->country,
                'city' => $merged->city,
                'ai_context' => $merged->ai_context,
                'is_important' => $merged->is_important,
                'important_at' => $merged->important_at?->toIso8601String(),
                'important_source' => $merged->important_source,
            ];

            ContactIdentity::query()
                ->where('contact_id', $merged->id)
                ->update(['contact_id' => $target->id]);

            $identityValues = array_values(array_filter(array_map(
                fn (array $identity): ?string => $identity['value'] ?? null,
                $identitySnapshots,
            )));

            $logPayload = [
                'related_contact_name' => $merged->name,
                'identity_values' => $identityValues,
                'identity_snapshots' => $identitySnapshots,
                'merged_attributes' => $attributeSnapshot,
            ];

            if (! empty($mergedCustomAttributes)) {
                $logPayload['merged_custom_attributes'] = $mergedCustomAttributes;
            }

            ContactAttributeValue::query()
                ->where('contact_id', $merged->id)
                ->delete();

            ContactActivityLogger::record(
                $target,
                ContactActivityLog::ACTION_MERGED_INTO_CURRENT,
                $actor,
                $merged,
                $logPayload,
            );

            ContactActivityLogger::record(
                $merged,
                ContactActivityLog::ACTION_MERGED_INTO_OTHER,
                $actor,
                $target,
                [
                    'related_contact_name' => $target->name,
                    'identity_values' => $identityValues,
                    'identity_snapshots' => $identitySnapshots,
                    'merged_attributes' => $attributeSnapshot,
                ],
            );

            $this->mergeTags($target, $merged);

            $merged->delete();

            $target->syncPrimaryFields();

            return $target->fresh();
        });
    }

    /**
     * @param  Collection<int, ContactIdentity>  $mergedIdentities
     */
    private function mergeAttributes(Contact $target, Contact $merged, Collection $mergedIdentities): void
    {
        $fillableNullFields = ['name', 'locale', 'timezone', 'country', 'city'];

        foreach ($fillableNullFields as $field) {
            if ($target->{$field} === null && $merged->{$field} !== null) {
                $target->{$field} = $merged->{$field};
            }
        }

        if ($merged->last_seen_at !== null) {
            if ($target->last_seen_at === null || $merged->last_seen_at->isAfter($target->last_seen_at)) {
                $target->last_seen_at = $merged->last_seen_at;
            }
        }

        if (! $target->is_important && $merged->is_important) {
            $target->is_important = true;
            $target->important_at = $merged->important_at;
            $target->important_by_user_id = $merged->important_by_user_id;
            $target->important_source = $merged->important_source;
        }

        $target->ai_context = ContactAiContext::merge($target->ai_context, $merged->ai_context);

        $shouldPromoteTarget = $target->type === ContactType::Contact
            || $merged->type === ContactType::Contact
            || $target->identities()
                ->whereIn('type', [
                    IdentityType::Email,
                    IdentityType::Phone,
                    IdentityType::ExternalId,
                ])
                ->exists()
            || $mergedIdentities->contains(
                fn (ContactIdentity $identity) => ContactIdentityNormalizer::promotesContactType($identity->type)
            );

        if ($shouldPromoteTarget && $target->type !== ContactType::Contact) {
            $target->type = ContactType::Contact;
        }

        $target->saveQuietly();
    }

    /**
     * @return array<int, array{key: string, value: mixed}>
     */
    private function mergeCustomAttributes(Contact $target, Contact $merged): array
    {
        $targetValues = ContactAttributeValue::query()
            ->where('contact_id', $target->id)
            ->with('definition')
            ->get()
            ->keyBy('definition_id');

        $mergedValues = ContactAttributeValue::query()
            ->where('contact_id', $merged->id)
            ->with('definition')
            ->get()
            ->keyBy('definition_id');

        $snapshot = [];

        foreach ($mergedValues as $definitionId => $mergedVal) {
            $definition = $mergedVal->definition;

            if (! $definition) {
                continue;
            }

            $targetVal = $targetValues->get($definitionId);
            $mergedRaw = $mergedVal->value();
            $targetRaw = $targetVal?->value();

            $resultValue = $this->mergeCustomAttributeValue($definition, $targetRaw, $mergedRaw);
            $hasChanged = ! $targetVal || $targetRaw !== $resultValue;

            if ($resultValue === null || ! $hasChanged) {
                continue;
            }

            ContactAttributeValue::query()->updateOrCreate(
                [
                    'contact_id' => $target->id,
                    'definition_id' => $definitionId,
                ],
                [
                    'value_json' => ['value' => $resultValue],
                    'source' => AttributeValueSource::Merge,
                    'updated_by_user_id' => null,
                ],
            );

            $snapshot[] = [
                'key' => $definition->key,
                'old' => $targetRaw,
                'merged' => $mergedRaw,
                'value' => $resultValue,
            ];
        }

        return $snapshot;
    }

    private function mergeCustomAttributeValue(AttributeDefinition $definition, mixed $targetValue, mixed $mergedValue): mixed
    {
        if ($definition->type === AttributeType::MultiSelect) {
            $targetArr = $targetValue ?? [];
            $mergedArr = $mergedValue ?? [];
            $union = array_values(array_unique(array_merge($targetArr, $mergedArr)));

            return ! empty($union) ? $union : null;
        }

        if ($definition->type === AttributeType::Boolean) {
            if ($targetValue !== null) {
                return $targetValue;
            }

            return $mergedValue;
        }

        if ($targetValue !== null && $targetValue !== '') {
            return $targetValue;
        }

        return $mergedValue;
    }

    private function mergeTags(Contact $target, Contact $merged): void
    {
        $activeTagIds = Tag::query()
            ->pluck('id');

        $existingTagIds = DB::table('contact_tag_assignments')
            ->where('contact_id', $target->id)
            ->pluck('tag_id');

        $mergedAssignments = DB::table('contact_tag_assignments')
            ->where('contact_id', $merged->id)
            ->whereIn('tag_id', $activeTagIds)
            ->get();

        foreach ($mergedAssignments as $assignment) {
            if (! $existingTagIds->contains($assignment->tag_id)) {
                DB::table('contact_tag_assignments')->insert([
                    'tag_id' => $assignment->tag_id,
                    'contact_id' => $target->id,
                    'assigned_by_user_id' => $assignment->assigned_by_user_id,
                    'source' => $assignment->source,
                    'created_at' => $assignment->created_at,
                ]);
            }
        }

        DB::table('contact_tag_assignments')
            ->where('contact_id', $merged->id)
            ->delete();
    }

    public function asController(Request $request): Response
    {
        $data = FormMergeContactsData::from($request);

        $this->handle(
            $data->target_contact_id,
            $data->merged_contact_id,
            $request->user(),
        );

        return back();
    }
}
