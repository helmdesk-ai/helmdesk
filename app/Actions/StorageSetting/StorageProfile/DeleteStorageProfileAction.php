<?php

namespace App\Actions\StorageSetting\StorageProfile;

use App\Models\Attachment;
use App\Models\StorageProfile;
use App\Settings\StorageSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 删除未被使用的存储配置。
 */
class DeleteStorageProfileAction
{
    use AsAction;

    /**
     * 注入系统存储设置对象。
     */
    public function __construct(
        public StorageSettings $settings,
    ) {}

    /**
     * 删除未启用且未被附件引用的存储配置。
     */
    public function handle(StorageProfile $profile): void
    {
        if ($this->settings->enabled && $this->settings->current_profile_id === (string) $profile->id) {
            throw ValidationException::withMessages([
                'profile' => __('storage_settings.profile_is_active_cannot_delete'),
            ]);
        }

        $refCount = Attachment::query()->where('storage_profile_id', $profile->id)->count();
        if ($refCount > 0) {
            throw ValidationException::withMessages([
                'profile' => __('storage_settings.profile_is_referenced_cannot_delete'),
            ]);
        }

        $profile->delete();
    }

    /**
     * 接收删除存储配置请求并返回上一页。
     */
    public function asController(Request $request, StorageProfile $profile): RedirectResponse
    {
        $this->handle($profile);

        return back();
    }
}
