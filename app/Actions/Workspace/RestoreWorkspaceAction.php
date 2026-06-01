<?php

namespace App\Actions\Workspace;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 恢复已删除工作区。
 */
class RestoreWorkspaceAction
{
    use AsAction;

    public function handle(string $id): void
    {
        $workspace = Workspace::onlyTrashed()->findOrFail($id);
        $workspace->restore();
    }

    public function asController(Request $request, string $id)
    {
        $this->handle($id);

        return back();
    }
}
