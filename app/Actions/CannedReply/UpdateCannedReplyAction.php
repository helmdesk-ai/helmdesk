<?php

namespace App\Actions\CannedReply;

use App\Data\CannedReply\FormUpdateCannedReplyData;
use App\Data\SystemUserContextData;
use App\Enums\UserPermission;
use App\Exceptions\BusinessException;
use App\Models\CannedReply;
use App\Models\User;
use App\Services\CannedReply\CannedReplyPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 更新快捷回复模版，支持在个人 <-> 系统共享之间切换归属。
 */
class UpdateCannedReplyAction
{
    use AsAction;

    public function __construct(
        private readonly CannedReplyPermission $policy,
    ) {}

    /**
     * 写入更新字段；权限不足或 shortcut 冲突会抛业务异常 / 校验异常。
     * 切换归属（个人 <-> 共享）需要系统共享管理权限。
     */
    public function handle(User $user, string $cannedReplyId, FormUpdateCannedReplyData $data): CannedReply
    {
        Gate::forUser($user)->authorize('user.permission', UserPermission::CannedRepliesEdit);

        $reply = CannedReply::query()
            ->find($cannedReplyId);

        if ($reply === null) {
            throw new NotFoundHttpException;
        }

        if (! $this->policy->canEdit($reply, $user)) {
            throw new BusinessException(__('canned_reply.errors.forbidden'));
        }

        $wasShared = $reply->user_id === null;
        $willBeShared = ! $data->is_personal;
        $isScopeChange = $wasShared !== $willBeShared;

        if ($isScopeChange && ! $this->policy->canManageSystemShared($user)) {
            throw new BusinessException(__('canned_reply.errors.forbidden'));
        }

        $targetUserId = $willBeShared ? null : ($wasShared ? $user->id : $reply->user_id);

        $shortcut = $this->normalizeShortcut($data->shortcut);
        $this->guardShortcutUnique($reply, $shortcut, $targetUserId);

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
        $ctx = SystemUserContextData::fromRequest($request);
        $user = User::query()->findOrFail($ctx->user_id);

        $this->handle($user, $cannedReply, FormUpdateCannedReplyData::from($request));

        return redirect()->route('admin.canned-replies.index');
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
     * targetUserId 为 null 表示系统共享范围。
     */
    private function guardShortcutUnique(CannedReply $current, ?string $shortcut, ?string $targetUserId): void
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
