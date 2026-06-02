<?php

namespace App\Actions\CannedReply;

use App\Data\CannedReply\FormUpdateCannedReplyData;
use App\Data\WorkspaceUserContextData;
use App\Exceptions\BusinessException;
use App\Models\CannedReply;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CannedReply\CannedReplyPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 更新快捷回复模版，支持在个人 <-> 工作区共享之间切换归属。
 */
class UpdateCannedReplyAction
{
    use AsAction;

    public function __construct(
        private readonly CannedReplyPermission $policy,
    ) {}

    /**
     * 写入更新字段；权限不足或 shortcut 冲突会抛业务异常 / 校验异常。
     * 切换归属（个人 <-> 共享）需要工作区共享管理权限。
     */
    public function handle(Workspace $workspace, User $user, string $cannedReplyId, FormUpdateCannedReplyData $data): CannedReply
    {
        $reply = CannedReply::query()
            ->find($cannedReplyId);

        if ($reply === null) {
            throw new NotFoundHttpException;
        }

        if (! $this->policy->canEdit($reply, $workspace, $user)) {
            throw new BusinessException(__('canned_reply.errors.forbidden'));
        }

        $wasShared = $reply->user_id === null;
        $willBeShared = ! $data->is_personal;
        $isScopeChange = $wasShared !== $willBeShared;

        if ($isScopeChange && ! $this->policy->canManageWorkspaceShared($workspace, $user)) {
            throw new BusinessException(__('canned_reply.errors.forbidden'));
        }

        $targetUserId = $willBeShared ? null : ($wasShared ? $user->id : $reply->user_id);

        $shortcut = $this->normalizeShortcut($data->shortcut);
        $this->guardShortcutUnique($workspace, $reply, $shortcut, $targetUserId);

        $reply->fill([
            'name' => trim($data->name),
            'shortcut' => $shortcut,
            'content' => $data->content,
            'user_id' => $targetUserId,
            'updated_by_user_id' => $user->id,
        ])->save();

        return $reply->refresh();
    }

    /**
     * Inertia 入口。
     */
    public function asController(Request $request, string $cannedReply): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $workspace = $ctx->workspace();
        $user = User::query()->findOrFail($ctx->user_id);

        $this->handle($workspace, $user, $cannedReply, FormUpdateCannedReplyData::from($request));

        return redirect()->route('workspace.canned-replies.index');
    }

    /**
     * 把 shortcut 规整为小写、去空白；空字符串视为 null。
     */
    private function normalizeShortcut(?string $shortcut): ?string
    {
        if ($shortcut === null) {
            return null;
        }

        $trimmed = strtolower(trim($shortcut));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * 排除自身后检查目标归属（targetUserId）下 shortcut 唯一。
     * targetUserId 为 null 表示工作区共享范围。
     */
    private function guardShortcutUnique(Workspace $workspace, CannedReply $current, ?string $shortcut, ?string $targetUserId): void
    {
        if ($shortcut === null) {
            return;
        }

        $query = CannedReply::query()
            ->where('shortcut', $shortcut)
            ->whereKeyNot($current->id);

        if ($targetUserId === null) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $targetUserId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'shortcut' => __('canned_reply.errors.shortcut_exists'),
            ]);
        }
    }
}
