<?php

namespace App\Actions\Tag;

use App\Data\WorkspaceUserContextData;
use App\Exceptions\BusinessException;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 软删除工作区标签。
 */
class DeleteTagAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $id): void
    {
        $tag = Tag::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($id);

        if ($tag->is_locked) {
            throw new BusinessException(__('tag.errors.locked_cannot_delete'));
        }

        $affectedContactIds = DB::table('contact_tag_assignments')
            ->where('tag_id', $tag->id)
            ->pluck('contact_id');

        $tag->delete();

        if ($affectedContactIds->isNotEmpty()) {
            Contact::query()
                ->whereIn('id', $affectedContactIds)
                ->get()
                ->each
                ->searchable();
        }
    }

    public function asController(Request $request, string $slug, string $id)
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $currentWorkspace = $ctx->workspace();
        $this->handle($currentWorkspace, $id);

        return back();
    }
}
