<?php

namespace App\Actions\Manage;

use App\Data\CurrentWorkspace\FormCreateWorkspaceData;
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
 * 在管理中心创建系统工作区并绑定负责人。
 */
class CreateWorkspaceAction
{
    use AsAction;

    /**
     * 注入附件绑定服务。
     */
    public function __construct(
        private readonly AttachmentBindingService $attachments,
    ) {}

    /**
     * 创建当前用户拥有的新工作区。
     */
    public function handle(User $user, FormCreateWorkspaceData $data): Workspace
    {
        return DB::transaction(function () use ($user, $data) {
            $this->attachments->assertAssignable(
                attachable: new Workspace,
                attachmentId: $data->logo_id,
                currentAttachmentId: null,
                workspaceId: null,
                allowedPurposes: [AttachmentPurpose::Avatar],
                messageKey: 'channel.messages.invalid_attachment',
            );

            $workspace = Workspace::query()->create(array_merge($data->toArray(), [
                'owner_id' => $user->id,
            ]));
            $this->attachments->syncAttachment($workspace, 'logo_id', null, (string) $workspace->id);
            $user->workspaces()->attach($workspace->id, ['role' => WorkspaceRole::Owner]);

            return $workspace;
        });
    }

    /**
     * 接收当前工作区创建表单并跳转到新工作区。
     */
    public function asController(Request $request): RedirectResponse
    {
        $data = FormCreateWorkspaceData::from($request);
        $newWorkspace = $this->handle($request->user(), $data);

        return redirect(route('workspace.manage.workspaces.current.show', $newWorkspace->slug));
    }
}
