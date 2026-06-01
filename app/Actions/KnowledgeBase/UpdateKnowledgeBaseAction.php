<?php

namespace App\Actions\KnowledgeBase;

use App\Data\KnowledgeBase\FormUpdateKnowledgeBaseData;
use App\Data\WorkspaceUserContextData;
use App\Enums\AttachmentPurpose;
use App\Models\KnowledgeBase;
use App\Models\Workspace;
use App\Services\Storage\AttachmentBindingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新工作区知识库基础信息。
 */
class UpdateKnowledgeBaseAction
{
    use AsAction;

    /**
     * 注入附件绑定服务。
     */
    public function __construct(
        private readonly AttachmentBindingService $attachments,
    ) {}

    /**
     * 更新知识库名称、头像和描述。
     */
    public function handle(Workspace $workspace, KnowledgeBase $knowledgeBase, FormUpdateKnowledgeBaseData $data): void
    {
        $name = trim($data->name);
        $this->ensureNameIsAvailable($workspace, $name, (string) $knowledgeBase->id);
        $originalAvatarId = filled($knowledgeBase->avatar_id) ? (string) $knowledgeBase->avatar_id : null;
        $this->attachments->assertAssignable(
            attachable: $knowledgeBase,
            attachmentId: $data->avatar_id,
            currentAttachmentId: $originalAvatarId,
            workspaceId: (string) $workspace->id,
            allowedPurposes: [AttachmentPurpose::Avatar],
            messageKey: 'knowledge_base.messages.invalid_attachment',
        );

        $knowledgeBase->update([
            'name' => $name,
            'avatar_id' => filled($data->avatar_id) ? $data->avatar_id : null,
            'description' => filled($data->description) ? $data->description : null,
        ]);
        $this->attachments->syncAttachment($knowledgeBase, 'avatar_id', $originalAvatarId, (string) $workspace->id);
    }

    /**
     * 接收编辑知识库表单并回到当前页面。
     */
    public function asController(Request $request, string $slug, string $knowledgeBase): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $knowledgeBaseModel = KnowledgeBase::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($knowledgeBase);

        $this->handle($workspace, $knowledgeBaseModel, FormUpdateKnowledgeBaseData::from($request));

        return back();
    }

    /**
     * 校验当前工作区内知识库名称是否可用。
     */
    private function ensureNameIsAvailable(Workspace $workspace, string $name, string $exceptId): void
    {
        $exists = KnowledgeBase::query()
            ->where('workspace_id', $workspace->id)
            ->where('name', $name)
            ->whereKeyNot($exceptId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('knowledge_base.messages.name_exists'),
            ]);
        }
    }
}
