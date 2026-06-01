<?php

namespace App\Actions\Workspace;

use App\Data\Workspace\FormCreateSystemWorkspaceData;
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
 * 在超级管理后台创建系统工作区并指定拥有者。
 */
class CreateSystemWorkspaceAction
{
    use AsAction;

    /**
     * 注入附件绑定服务。
     */
    public function __construct(
        private readonly AttachmentBindingService $attachments,
    ) {}

    /**
     * 创建系统后台工作区并指定拥有者。
     */
    public function handle(FormCreateSystemWorkspaceData $data): Workspace
    {
        return DB::transaction(function () use ($data) {
            $owner = User::query()
                ->where('is_super_admin', false)
                ->findOrFail($data->owner_id);

            $this->attachments->assertAssignable(
                attachable: new Workspace,
                attachmentId: $data->logo_id,
                currentAttachmentId: null,
                workspaceId: null,
                allowedPurposes: [AttachmentPurpose::Avatar],
                messageKey: 'channel.messages.invalid_attachment',
            );

            $workspace = Workspace::query()->create([
                'name' => $data->name,
                'slug' => $data->slug,
                'logo_id' => $data->logo_id,
                'owner_id' => $owner->id,
            ]);
            $this->attachments->syncAttachment($workspace, 'logo_id', null, (string) $workspace->id);

            $workspace->users()->attach($owner->id, [
                'role' => WorkspaceRole::Owner,
            ]);

            return $workspace;
        });
    }

    /**
     * 接收后台新建工作区表单并返回工作区列表。
     */
    public function asController(Request $request): RedirectResponse
    {
        $data = FormCreateSystemWorkspaceData::from($request);
        $this->handle($data);

        return redirect()->route('admin.workspaces.index');
    }
}
