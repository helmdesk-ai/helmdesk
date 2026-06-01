<?php

namespace App\Actions\Workspace;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 以工作区拥有者身份进入指定工作区。
 */
class LoginAsWorkspaceOwnerAction
{
    use AsAction;

    public function handle(string $id): RedirectResponse
    {
        $workspace = Workspace::query()
            ->with(['owner' => fn ($query) => $query->withTrashed()])
            ->findOrFail($id);

        $owner = $workspace->owner;
        if (! $owner) {
            abort(404, 'Workspace owner not found.');
        }

        if ($owner->deleted_at) {
            abort(404, 'Workspace owner has been deleted.');
        }

        Auth::guard('web')->login($owner);

        return redirect()->to(route('workspace.dashboard', ['slug' => $workspace->slug], absolute: false));
    }

    public function asController(Request $request, string $id): RedirectResponse
    {
        return $this->handle($id);
    }
}
