<?php

namespace App\Actions\Contact;

use App\Data\WorkspaceUserContextData;
use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\ContactIdentity;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contact\ContactActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 删除联系人下的一个身份标识。
 */
class DeleteContactIdentityAction
{
    use AsAction;

    public function handle(
        Workspace $workspace,
        string $contactId,
        string $identityId,
        ?User $actor = null,
    ): void {
        $contact = Contact::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($contactId);

        $identity = ContactIdentity::query()
            ->where('workspace_id', $workspace->id)
            ->where('contact_id', $contact->id)
            ->findOrFail($identityId);

        if (! $identity->type->supportsManualManagement()) {
            throw ValidationException::withMessages([
                'identity' => __('contact.identity_manual_management_not_supported'),
            ]);
        }

        DB::transaction(function () use ($contact, $identity, $actor) {
            $identity->delete();
            $contact->syncPrimaryFields();
            ContactActivityLogger::record(
                $contact,
                ContactActivityLog::ACTION_IDENTITY_DELETED,
                $actor,
                payload: [
                    'identity_type' => $identity->type->value,
                    'identity_value' => $identity->value,
                    'identity_values' => [$identity->value],
                ],
            );
        });
    }

    public function asController(
        Request $request,
        string $slug,
        string $contactId,
        string $identityId,
    ): Response {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $workspace = $ctx->workspace();

        $this->handle($workspace, $contactId, $identityId, $request->user());

        return back();
    }
}
