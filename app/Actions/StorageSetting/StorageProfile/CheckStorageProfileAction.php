<?php

namespace App\Actions\StorageSetting\StorageProfile;

use App\Models\StorageProfile;
use App\Services\Storage\StorageCorsChecker;
use App\Services\Storage\StorageProfileDisk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * 校验单个存储配置是否可用。
 */
class CheckStorageProfileAction
{
    use AsAction;

    /**
     * 注入存储桶 CORS 检测服务。
     */
    public function __construct(
        private readonly StorageCorsChecker $corsChecker,
    ) {}

    /**
     * 检测指定存储配置的连接和浏览器直传 CORS 能力。
     */
    public function handle(StorageProfile $profile, string $origin): void
    {
        try {
            $disk = StorageProfileDisk::build($profile);
            $path = 'health-check/'.str()->ulid().'.txt';

            $disk->put($path, 'ok');
            $disk->size($path);
            $disk->delete($path);
        } catch (Throwable $e) {
            Log::warning('Storage profile connection check failed', [
                'storage_profile_id' => $profile->id,
                'exception' => $e,
            ]);

            throw ValidationException::withMessages([
                'profile' => __('storage_settings.connection_check_failed'),
            ]);
        }

        $this->corsChecker->assertSupportsBrowserUploads($profile, $origin);
    }

    /**
     * 接收单个存储配置检测请求并返回 toast 结果。
     */
    public function asController(Request $request, StorageProfile $profile): RedirectResponse
    {
        try {
            $this->handle($profile, StorageCorsChecker::browserOriginFromRequest($request));
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

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('storage_settings.connection_check_success'),
        ]);

        return back();
    }
}
