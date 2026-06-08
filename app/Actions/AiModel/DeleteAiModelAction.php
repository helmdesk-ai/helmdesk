<?php

namespace App\Actions\AiModel;

use App\Enums\UserPermission;
use App\Models\AiModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除一个全局 AI 模型，立即移出运行时按用途取用的全局池。
 */
class DeleteAiModelAction
{
    use AsAction;

    /**
     * 删除模型记录。
     */
    public function handle(string $modelId): void
    {
        AiModel::query()->whereKey($modelId)->delete();
    }

    /**
     * 鉴权后删除并返回上一页。
     */
    public function asController(Request $request, string $model): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::SystemSettingsEdit);

        $this->handle($model);

        return back();
    }
}
