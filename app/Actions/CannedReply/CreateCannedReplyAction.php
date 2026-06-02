<?php

namespace App\Actions\CannedReply;

use App\Data\CannedReply\FormCreateCannedReplyData;
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

/**
 * 创建快捷回复模版。
 * 个人模版任何成员都可创建；共享模版仅工作区管理员可创建。
 */
class CreateCannedReplyAction
{
    use AsAction;

    public function __construct(
        private readonly CannedReplyPermission $policy,
    ) {}

    /**
     * 持久化新模版并返回模型。
     */
    public function handle(Workspace $workspace, User $user, FormCreateCannedReplyData $data): CannedReply
    {
        $isPersonal = $data->is_personal;

        if (! $isPersonal && ! $this->policy->canManageWorkspaceShared($workspace, $user)) {
            throw new BusinessException(__('canned_reply.errors.workspace_create_forbidden'));
        }

        $shortcut = $this->normalizeShortcut($data->shortcut);
        $userId = $isPersonal ? (string) $user->id : null;

        $this->guardShortcutUnique($workspace, $userId, $shortcut);

        return CannedReply::query()->create([
            'user_id' => $userId,
            'name' => trim($data->name),
            'shortcut' => $shortcut,
            'content' => $data->content,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
    }

    /**
     * Inertia 入口：保存后回到列表页并 toast 成功。
     */
    public function asController(Request $request): RedirectResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $workspace = $ctx->workspace();
        $user = User::query()->findOrFail($ctx->user_id);

        $this->handle($workspace, $user, FormCreateCannedReplyData::from($request));

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
     * 同 workspace + 同归属下，shortcut 不可重复。
     */
    private function guardShortcutUnique(Workspace $workspace, ?string $userId, ?string $shortcut): void
    {
        if ($shortcut === null) {
            return;
        }

        $query = CannedReply::query()
            ->where('shortcut', $shortcut);

        if ($userId === null) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $userId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'shortcut' => __('canned_reply.errors.shortcut_exists'),
            ]);
        }
    }
}
