<?php

namespace App\Actions\KnowledgeBase;

use App\Data\KnowledgeBase\FormUpdateKnowledgeBaseData;
use App\Enums\AttachmentPurpose;
use App\Enums\UserPermission;
use App\Models\KnowledgeBase;
use App\Services\Storage\AttachmentBindingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新系统知识库基础信息。
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
    public function handle(KnowledgeBase $knowledgeBase, FormUpdateKnowledgeBaseData $data): void
    {
        $name = trim($data->name);
        $this->ensureNameIsAvailable($name, (string) $knowledgeBase->id);
        $originalAvatarId = filled($knowledgeBase->avatar_id) ? (string) $knowledgeBase->avatar_id : null;
        $this->attachments->assertAssignable(
            attachable: $knowledgeBase,
            attachmentId: $data->avatar_id,
            currentAttachmentId: $originalAvatarId,
            allowedPurposes: [AttachmentPurpose::Avatar],
            messageKey: 'knowledge_base.messages.invalid_attachment',
        );

        $knowledgeBase->update([
            'name' => $name,
            'avatar_id' => filled($data->avatar_id) ? $data->avatar_id : null,
            'description' => filled($data->description) ? $data->description : null,
        ]);
        $this->attachments->syncAttachment($knowledgeBase, 'avatar_id', $originalAvatarId);
    }

    /**
     * 接收编辑知识库表单并回到当前页面。
     */
    public function asController(Request $request, string $knowledgeBase): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::KnowledgeBasesEdit);

        $knowledgeBaseModel = KnowledgeBase::query()
            ->findOrFail($knowledgeBase);

        $this->handle($knowledgeBaseModel, FormUpdateKnowledgeBaseData::from($request));

        return back();
    }

    /**
     * 校验当前系统内知识库名称是否可用。
     */
    private function ensureNameIsAvailable(string $name, string $exceptId): void
    {
        $exists = KnowledgeBase::query()
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
