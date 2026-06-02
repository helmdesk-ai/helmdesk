<?php

namespace App\Actions\Tag;

use App\Data\SystemUserContextData;
use App\Exceptions\BusinessException;
use App\Models\Contact;
use App\Models\SystemContext;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 软删除系统标签。
 */
class DeleteTagAction
{
    use AsAction;

    public function handle(SystemContext $systemContext, string $id): void
    {
        $tag = Tag::query()
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

    public function asController(Request $request, string $id)
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $currentSystem = $ctx->systemContext();
        $this->handle($currentSystem, $id);

        return back();
    }
}
