<?php

namespace App\Actions\CannedReply;

use App\Data\WorkspaceUserContextData;
use App\Exceptions\BusinessException;
use App\Models\CannedReply;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CannedReply\CannedReplyPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 软删除快捷回复模版。
 */
class DeleteCannedReplyAction
{
    use AsAction;

    public function __construct(
        private readonly CannedReplyPermission $policy,
    ) {}

    /**
     * 软删模版；找不到 -> 404；权限不足 -> 业务异常 toast。
     */
    public function handle(Workspace $workspace, User $user, string $cannedReplyId): void
    {
        $reply = CannedReply::query()
            ->find($cannedReplyId);

        if ($reply === null) {
            throw new NotFoundHttpException;
        }

        if (! $this->policy->canDelete($reply, $workspace, $user)) {
            throw new BusinessException(__('canned_reply.errors.forbidden'));
        }

        $reply->delete();
    }

    /**
     * Inertia 入口。
     */
    public function asController(Request $request, string $cannedReply): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $workspace = $ctx->workspace();
        $user = User::query()->findOrFail($ctx->user_id);

        $this->handle($workspace, $user, $cannedReply);

        return redirect()->route('workspace.canned-replies.index');
    }
}
