<?php

namespace App\Actions\Manage;

use App\Data\CurrentWorkspace\FormUpdateWorkspaceData;
use App\Data\WorkspaceUserContextData;
use App\Enums\AttachmentPurpose;
use App\Models\Workspace;
use App\Services\Storage\AttachmentBindingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新管理中心里的工作区基础资料。
 */
class UpdateWorkspaceAction
{
    use AsAction;

    /**
     * 注入附件绑定服务。
     */
    public function __construct(
        private readonly AttachmentBindingService $attachments,
    ) {}

    /**
     * 更新当前工作区基础资料。
     */
    public function handle(Workspace $workspace, FormUpdateWorkspaceData $data): void
    {
        $originalLogoId = filled($workspace->logo_id) ? (string) $workspace->logo_id : null;

        $this->attachments->assertAssignable(
            attachable: $workspace,
            attachmentId: $data->logo_id,
            currentAttachmentId: $originalLogoId,
            workspaceId: (string) $workspace->id,
            allowedPurposes: [AttachmentPurpose::Avatar],
            messageKey: 'channel.messages.invalid_attachment',
        );
        $workspace->update($data->toArray());
        $this->attachments->syncAttachment($workspace, 'logo_id', $originalLogoId, (string) $workspace->id);
    }

    /**
     * 接收当前工作区编辑表单并返回工作区首页。
     */
    public function asController(Request $request): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $currentWorkspace = $ctx->workspace();
        $data = FormUpdateWorkspaceData::from($request);
        $this->handle($currentWorkspace, $data);

        return redirect()->route('workspace.manage.workspaces.current.show', ['slug' => $ctx->workspaceSlug()]);
    }
}
