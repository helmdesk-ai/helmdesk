<?php

namespace App\Actions\Contact;

use App\Data\Contact\FormCreateContactIdentityData;
use App\Data\WorkspaceUserContextData;
use App\Enums\ContactType;
use App\Enums\IdentityType;
use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\ContactIdentity;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contact\ContactActivityLogger;
use App\Services\Contact\ContactIdentityNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 给联系人新增邮箱、手机号等身份标识。
 */
class CreateContactIdentityAction
{
    use AsAction;

    public function handle(
        Workspace $workspace,
        string $contactId,
        FormCreateContactIdentityData $data,
        ?User $actor = null,
    ): ContactIdentity {
        $contact = Contact::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($contactId);

        $type = IdentityType::from($data->type);

        if ($type === IdentityType::Phone && ! ContactIdentityNormalizer::isPhoneInputFormatValid($data->value)) {
            throw ValidationException::withMessages([
                'value' => __('contact.invalid_phone'),
            ]);
        }

        $value = ContactIdentityNormalizer::normalizeValue($type, $data->value);
        $namespace = $data->namespace ?? '';

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

        $existing = ContactIdentity::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', $type)
            ->where('namespace', $namespace)
            ->where('value', $value)
            ->whereNull('deleted_at')
            ->with('contact')
            ->first();

        if ($existing) {
            $contactName = $existing->contact->name ?? $existing->contact->id;

            throw ValidationException::withMessages([
                'value' => __('contact.identity_already_exists', [
                    'type' => $type->label(),
                    'name' => $contactName,
                ]),
            ]);
        }

        $shouldPromote = ContactIdentityNormalizer::promotesContactType($type);

        return DB::transaction(function () use ($contact, $type, $value, $namespace, $shouldPromote, $actor) {
            $wasVisitor = $contact->type === ContactType::Visitor;

            $identity = ContactIdentity::query()->create([
                'workspace_id' => $contact->workspace_id,
                'contact_id' => $contact->id,
                'type' => $type,
                'namespace' => $namespace,
                'value' => $value,
                'display_value' => ContactIdentityNormalizer::buildDisplayValue($type, $value),
            ]);

            if ($shouldPromote && $contact->type === ContactType::Visitor) {
                $contact->type = ContactType::Contact;
                $contact->saveQuietly();
            }

            $contact->syncPrimaryFields();
            ContactActivityLogger::record(
                $contact,
                ContactActivityLog::ACTION_IDENTITY_ADDED,
                $actor,
                payload: [
                    'identity_type' => $type->value,
                    'identity_value' => $value,
                    'identity_values' => [$value],
                    'promoted_to_contact' => $shouldPromote && $wasVisitor,
                ],
            );

            return $identity;
        });
    }

    public function asController(Request $request, string $slug, string $contactId): Response
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $workspace = $ctx->workspace();
        $data = FormCreateContactIdentityData::from($request);

        $this->handle($workspace, $contactId, $data, $request->user());

        return back();
    }
}
