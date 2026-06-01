<?php

namespace App\Actions\Workspace;

use App\Data\Workspace\FormUpdateSystemWorkspaceData;
use App\Enums\AttachmentPurpose;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Storage\AttachmentBindingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 在超级管理后台更新系统工作区的资料、负责人和状态。
 */
class UpdateSystemWorkspaceAction
{
    use AsAction;

    /**
     * 注入附件绑定服务。
     */
    public function __construct(
        private readonly AttachmentBindingService $attachments,
    ) {}

    /**
     * 更新系统后台工作区资料、Logo 和拥有者。
     */
    public function handle(string $id, FormUpdateSystemWorkspaceData $data): void
    {
        DB::transaction(function () use ($id, $data) {
            $workspace = Workspace::query()->findOrFail($id);

            $owner = User::query()
                ->where('is_super_admin', false)
                ->findOrFail($data->owner_id);

            $originalLogoId = filled($workspace->logo_id) ? (string) $workspace->logo_id : null;

            $this->attachments->assertAssignable(
                attachable: $workspace,
                attachmentId: $data->logo_id,
                currentAttachmentId: $originalLogoId,
                workspaceId: (string) $workspace->id,
                allowedPurposes: [AttachmentPurpose::Avatar],
                messageKey: 'channel.messages.invalid_attachment',
            );

            $oldOwnerId = filled($workspace->owner_id) ? (string) $workspace->owner_id : null;

            $workspace->update([
                'name' => $data->name,
                'slug' => $data->slug,
                'logo_id' => $data->logo_id,
                'owner_id' => $owner->id,
            ]);
            $this->attachments->syncAttachment($workspace, 'logo_id', $originalLogoId, (string) $workspace->id);

            $newOwnerId = (string) $owner->id;

            if ($workspace->users()->whereKey($newOwnerId)->exists()) {
                $workspace->users()->updateExistingPivot($newOwnerId, ['role' => WorkspaceRole::Owner]);
            } else {
                $workspace->users()->attach($newOwnerId, ['role' => WorkspaceRole::Owner]);
            }

            if ($oldOwnerId && $oldOwnerId !== $newOwnerId) {
                if ($workspace->users()->whereKey($oldOwnerId)->exists()) {
                    $workspace->users()->updateExistingPivot($oldOwnerId, ['role' => WorkspaceRole::Admin]);
                }
            }
        });
    }

    /**
     * 接收后台编辑工作区表单并返回工作区列表。
     */
    public function asController(Request $request, string $id): RedirectResponse
    {
        $data = FormUpdateSystemWorkspaceData::from($request);
        $this->handle($id, $data);

        return redirect()->route('admin.workspaces.index');
    }
}
