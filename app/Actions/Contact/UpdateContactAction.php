<?php

namespace App\Actions\Contact;

use App\Data\Contact\FormUpdateContactData;
use App\Data\WorkspaceUserContextData;
use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contact\ContactActivityLogger;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 更新联系人资料、备注和地区信息。
 */
class UpdateContactAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $contactId, FormUpdateContactData $data, ?User $actor = null): Contact
    {
        $contact = Contact::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($contactId);

        $updates = [];
        $fieldChanges = [];

        if ($data->name !== null) {
            $normalizedName = trim($data->name) ?: null;

            if ($normalizedName !== $contact->name) {
                $updates['name'] = $normalizedName;
                $fieldChanges['name'] = [
                    'old' => $contact->name,
                    'new' => $normalizedName,
                ];
            }
        }

        if ($data->type !== null) {
            $normalizedType = ContactType::from($data->type);

            if ($normalizedType !== $contact->type) {
                $updates['type'] = $normalizedType;
                $fieldChanges['type'] = [
                    'old' => $contact->type->value,
                    'new' => $normalizedType->value,
                ];
            }
        }

        if ($data->note !== null) {
            $normalizedNote = trim($data->note) ?: null;

            if ($normalizedNote !== $contact->note) {
                $updates['note'] = $normalizedNote;
                $fieldChanges['note'] = [
                    'old' => $contact->note,
                    'new' => $normalizedNote,
                ];
            }
        }

        foreach (['country', 'city'] as $field) {
            if ($data->{$field} === null) {
                continue;
            }

            $normalizedValue = trim($data->{$field}) ?: null;

            if ($normalizedValue !== $contact->{$field}) {
                $updates[$field] = $normalizedValue;
                $fieldChanges[$field] = [
                    'old' => $contact->{$field},
                    'new' => $normalizedValue,
                ];
            }
        }

        if (! empty($updates)) {
            $contact->update($updates);
            ContactActivityLogger::record(
                $contact,
                ContactActivityLog::ACTION_UPDATED,
                $actor,
                payload: [
                    'field_changes' => $fieldChanges,
                ],
            );
        }

        return $contact;
    }

    public function asController(Request $request, string $slug, string $id): Response
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $workspace = $ctx->workspace();
        $data = FormUpdateContactData::from($request);

        $this->handle($workspace, $id, $data, $request->user());

        return back();
    }
}
