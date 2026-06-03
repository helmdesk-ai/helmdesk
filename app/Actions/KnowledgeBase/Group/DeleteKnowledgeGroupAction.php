<?php

namespace App\Actions\KnowledgeBase\Group;

use App\Enums\UserPermission;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除知识库分组，仅当分组下没有子分组时允许删除。
 */
class DeleteKnowledgeGroupAction
{
    use AsAction;

    /**
     * 删除指定分组，有子分组时拒绝。
     */
    public function handle(KnowledgeGroup $group): void
    {
        if ($group->is_default) {
            throw ValidationException::withMessages([
                'group' => __('knowledge_base.groups.default_locked'),
            ]);
        }

        if ($group->children()->exists()) {
            throw ValidationException::withMessages([
                'group' => __('knowledge_base.groups.has_children'),
            ]);
        }

        if ($group->documents()->exists()) {
            throw ValidationException::withMessages([
                'group' => __('knowledge_base.groups.has_documents'),
            ]);
        }

        if ($group->qaEntries()->exists()) {
            throw ValidationException::withMessages([
                'group' => __('knowledge_base.groups.has_documents'),
            ]);
        }

        $group->delete();
    }

    /**
     * 处理「删除分组」请求，校验权限并返回上一页。
     */
    public function asController(Request $request, string $knowledgeBase, string $group): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::KnowledgeBasesDelete);

        $kb = KnowledgeBase::query()->findOrFail($knowledgeBase);
        $groupModel = KnowledgeGroup::query()->where('knowledge_base_id', $kb->id)->findOrFail($group);

        $this->handle($groupModel);

        return back();
    }
}
