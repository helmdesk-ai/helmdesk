<?php

namespace App\Actions\Contact;

use App\Data\Contact\FormUpdateContactImportanceData;
use App\Data\WorkspaceUserContextData;
use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contact\ContactActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 标记或取消标记工作区联系人为重点客户。
 */
class UpdateContactImportanceAction
{
    use AsAction;

    /**
     * 更新重点客户标记并写入联系人活动日志。
     */
    public function handle(Workspace $workspace, string $contactId, FormUpdateContactImportanceData $data, ?User $actor = null): Contact
    {
        $contact = Contact::query()
            ->findOrFail($contactId);

        if ($contact->is_important === $data->is_important) {
            return $contact;
        }

        $contact->forceFill($data->is_important
            ? [
                'is_important' => true,
                'important_at' => now(),
                'important_by_user_id' => $actor?->id,
                'important_source' => 'manual',
            ]
            : [
                'is_important' => false,
                'important_at' => null,
                'important_by_user_id' => null,
                'important_source' => null,
            ])->save();

        ContactActivityLogger::record(
            contact: $contact,
            action: $data->is_important
                ? ContactActivityLog::ACTION_IMPORTANT_MARKED
                : ContactActivityLog::ACTION_IMPORTANT_UNMARKED,
            actor: $actor,
        );

        return $contact;
    }

    /**
     * 从 HTTP 请求中切换联系人重点标记。
     */
    public function asController(Request $request, string $id): JsonResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $contact = $this->handle(
            $ctx->workspace(),
            $id,
            FormUpdateContactImportanceData::from($request),
            $request->user(),
        );

        return response()->json([
            'is_important' => $contact->is_important,
            'important_at' => $contact->important_at?->toIso8601String(),
        ]);
    }
}
