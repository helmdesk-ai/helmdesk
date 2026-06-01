<?php

namespace App\Actions\Contact;

use App\Data\WorkspaceUserContextData;
use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contact\ContactActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

/**
 * 软删除联系人并保留后续恢复能力。
 */
class DeleteContactAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $contactId, ?User $actor = null): void
    {
        $contact = Contact::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($contactId);

        DB::transaction(function () use ($contact, $actor) {
            $contact->identities()->each(fn ($identity) => $identity->delete());
            $contact->delete();
            ContactActivityLogger::record($contact, ContactActivityLog::ACTION_DELETED, $actor);
        });
    }

    public function asController(Request $request, string $slug, string $id): Response
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $workspace = $ctx->workspace();

        $this->handle($workspace, $id, $request->user());

        return back();
    }
}
