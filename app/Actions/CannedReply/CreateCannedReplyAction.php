<?php

namespace App\Actions\CannedReply;

use App\Data\CannedReply\FormCreateCannedReplyData;
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

/**
 * 创建快捷回复模版。
 * 个人模版需要快捷回复编辑权限；共享模版需要快捷回复管理权限。
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
    public function handle(User $user, FormCreateCannedReplyData $data): CannedReply
    {
        Gate::forUser($user)->authorize('user.permission', UserPermission::CannedRepliesEdit);

        $isPersonal = $data->is_personal;

        if (! $isPersonal && ! $this->policy->canManageSystemShared($user)) {
            throw new BusinessException(__('canned_reply.errors.system_create_forbidden'));
        }

        $shortcut = $this->normalizeShortcut($data->shortcut);
        $userId = $isPersonal ? (string) $user->id : null;

        $this->guardShortcutUnique($userId, $shortcut);

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
        $user = $request->user();

        $this->handle($user, FormCreateCannedReplyData::from($request));

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
     * 同 system + 同归属下，shortcut 不可重复。
     */
    private function guardShortcutUnique(?string $userId, ?string $shortcut): void
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
