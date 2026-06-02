<?php

namespace App\Actions\Tag;

use App\Data\Tag\FormUpdateTagGroupData;
use App\Data\WorkspaceUserContextData;
use App\Models\TagGroup;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 重命名标签组；scope 不可更改，避免组内标签维度漂移。
 */
class UpdateTagGroupAction
{
    use AsAction;

    /**
     * 校验新组名唯一后重命名标签组。
     */
    public function handle(Workspace $workspace, string $id, FormUpdateTagGroupData $data, ?User $actor = null): TagGroup
    {
        $group = TagGroup::query()
            ->findOrFail($id);

        $name = trim($data->name);
        $normalizedName = mb_strtolower($name);

        $exists = TagGroup::query()
            ->where('normalized_name', $normalizedName)
            ->where('id', '!=', $group->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('tag.errors.group_name_exists'),
            ]);
        }

        $group->update([
            'name' => $name,
            'updated_by_user_id' => $actor?->id,
        ]);

        return $group;
    }

    /**
     * 接收重命名标签组表单提交并返回上一页。
     */
    public function asController(Request $request, string $id)
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $data = FormUpdateTagGroupData::from($request);
        $this->handle($ctx->workspace(), $id, $data, $request->user());

        return back();
    }
}
