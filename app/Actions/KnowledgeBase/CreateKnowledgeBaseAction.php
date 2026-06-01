<?php

namespace App\Actions\KnowledgeBase;

use App\Data\KnowledgeBase\FormCreateKnowledgeBaseData;
use App\Data\WorkspaceUserContextData;
use App\Enums\AttachmentPurpose;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeGroup;
use App\Models\Workspace;
use App\Services\Storage\AttachmentBindingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 创建工作区知识库基础信息。
 */
class CreateKnowledgeBaseAction
{
    use AsAction;

    /**
     * 注入附件绑定服务。
     */
    public function __construct(
        private readonly AttachmentBindingService $attachments,
    ) {}

    /**
     * 创建知识库并限定同一工作区内知识库名称唯一。
     */
    public function handle(Workspace $workspace, FormCreateKnowledgeBaseData $data): KnowledgeBase
    {
        $name = trim($data->name);
        $this->ensureNameIsAvailable($workspace, $name);
        $this->attachments->assertAssignable(
            attachable: new KnowledgeBase,
            attachmentId: $data->avatar_id,
            currentAttachmentId: null,
            workspaceId: (string) $workspace->id,
            allowedPurposes: [AttachmentPurpose::Avatar],
            messageKey: 'knowledge_base.messages.invalid_attachment',
        );

        return DB::transaction(function () use ($workspace, $data, $name): KnowledgeBase {
            $knowledgeBase = KnowledgeBase::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'avatar_id' => filled($data->avatar_id) ? $data->avatar_id : null,
                'description' => filled($data->description) ? $data->description : null,
                'category' => $data->category,
            ]);
            $this->attachments->syncAttachment($knowledgeBase, 'avatar_id', null, (string) $workspace->id);

            KnowledgeGroup::query()->create([
                'knowledge_base_id' => $knowledgeBase->id,
                'parent_id' => null,
                'name' => KnowledgeBase::DEFAULT_GROUP_NAME,
                'is_default' => true,
                'sort_order' => 0,
            ]);

            return $knowledgeBase;
        });
    }

    /**
     * 接收创建知识库表单并返回知识库列表页。
     */
    public function asController(Request $request): RedirectResponse
    {
        $workspace = WorkspaceUserContextData::fromRequest($request)->workspace();
        Gate::authorize('workspace.manageAi', [$workspace]);

        $knowledgeBase = $this->handle($workspace, FormCreateKnowledgeBaseData::from($request));

        return redirect()->route('workspace.manage.knowledge-bases.index', [
            'slug' => $workspace->slug,
            'kb' => $knowledgeBase->id,
        ]);
    }

    /**
     * 校验当前工作区内知识库名称是否可用。
     */
    private function ensureNameIsAvailable(Workspace $workspace, string $name): void
    {
        $exists = KnowledgeBase::query()
            ->where('workspace_id', $workspace->id)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('knowledge_base.messages.name_exists'),
            ]);
        }
    }
}
