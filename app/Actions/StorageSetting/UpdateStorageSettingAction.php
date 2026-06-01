<?php

namespace App\Actions\StorageSetting;

use App\Data\StorageSetting\FormStorageSettingData;
use App\Models\StorageProfile;
use App\Services\Storage\StorageCorsChecker;
use App\Services\Storage\StorageProfileDisk;
use App\Services\SystemSetting\SystemBaseUrl;
use App\Settings\StorageSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * 更新系统默认存储磁盘和访问 URL 配置。
 */
class UpdateStorageSettingAction
{
    use AsAction;

    /**
     * 注入系统存储设置和 CORS 检测服务。
     */
    public function __construct(
        public StorageSettings $settings,
        private readonly StorageCorsChecker $corsChecker,
    ) {}

    /**
     * 保存系统默认存储开关和当前存储配置。
     */
    public function handle(FormStorageSettingData $data, ?string $origin = null): void
    {
        if (! $data->enabled) {
            $this->settings->enabled = false;
            $this->settings->current_profile_id = null;
            $this->settings->save();

            return;
        }

        if (! filled($data->current_profile_id)) {
            throw ValidationException::withMessages([
                'current_profile_id' => __('storage_settings.storage_not_selected'),
            ]);
        }

        $profile = StorageProfile::query()->find($data->current_profile_id);
        if (! $profile) {
            throw ValidationException::withMessages([
                'current_profile_id' => __('storage_settings.storage_not_found'),
            ]);
        }

        if (! filled($profile->access_key) || ! filled($profile->secret_key)) {
            throw ValidationException::withMessages([
                'current_profile_id' => __('storage_settings.storage_key_secret_required'),
            ]);
        }

        try {
            $disk = StorageProfileDisk::build($profile);
            $path = 'health-check/'.str()->ulid().'.txt';

            $disk->put($path, 'ok');
            $disk->size($path);
            $disk->delete($path);
        } catch (Throwable $e) {
            Log::warning('Storage profile connection check failed during save', [
                'storage_profile_id' => $profile->id,
                'exception' => $e,
            ]);

            throw ValidationException::withMessages([
                'current_profile_id' => __('storage_settings.connection_check_failed'),
            ]);
        }

        $this->corsChecker->assertSupportsBrowserUploads($profile, $origin ?? app(SystemBaseUrl::class)->value());

        $this->settings->enabled = true;
        $this->settings->current_profile_id = $profile->id;
        $this->settings->save();
    }

    /**
     * 接收存储设置表单并返回保存结果。
     */
    public function asController(Request $request): RedirectResponse
    {
        $data = FormStorageSettingData::from($request);

        try {
            $this->handle($data, StorageCorsChecker::browserOriginFromRequest($request));
        } catch (ValidationException $e) {
            $message = collect($e->errors())
                ->flatten()
                ->unique()
                ->implode("\n");

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => $message !== ''
                    ? $message
                    : __('storage_settings.connection_check_failed'),
            ]);

            return back()->withErrors($e->errors());
        }

        return back();
    }
}
