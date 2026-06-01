<?php

namespace App\Actions\StorageSetting;

use App\Data\StorageSetting\FormCheckStorageSettingData;
use App\Enums\StorageProvider;
use App\Models\StorageProfile;
use App\Services\Storage\StorageCorsChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * 校验全局存储设置是否能正常写入和读取。
 */
class CheckStorageSettingAction
{
    use AsAction;

    /**
     * 注入存储桶 CORS 检测服务。
     */
    public function __construct(
        private readonly StorageCorsChecker $corsChecker,
    ) {}

    /**
     * 使用表单中的参数检测对象存储连接和浏览器直传 CORS。
     */
    public function handle(FormCheckStorageSettingData $data, string $origin): void
    {
        $payload = $data->toArray();

        if (! filled($payload['secret'] ?? null)) {
            throw ValidationException::withMessages([
                'secret' => __('storage_settings.secret_required'),
            ]);
        }

        $config = [
            'driver' => 's3',
            'key' => $payload['key'],
            'secret' => $payload['secret'],
            'region' => $payload['region'] ?? 'us-east-1',
            'bucket' => $payload['bucket'],
            'url' => $payload['url'] ?? null,
            'endpoint' => $payload['endpoint'] ?? null,
            'use_path_style_endpoint' => ($payload['provider'] ?? null) === StorageProvider::Minio->value,
            'throw' => true,
        ];
        $config = collect($config)->reject(fn ($v) => $v === null)->all();

        $disk = Storage::build($config);
        $path = 'health-check/'.str()->ulid().'.txt';

        $disk->put($path, 'ok');
        $disk->size($path);
        $disk->delete($path);

        $this->corsChecker->assertSupportsBrowserUploads($this->profileFromData($data), $origin);
    }

    /**
     * 接收存储连接检测请求并返回 toast 或字段错误。
     */
    public function asController(Request $request): RedirectResponse
    {
        $data = FormCheckStorageSettingData::from($request);

        try {
            $this->handle($data, StorageCorsChecker::browserOriginFromRequest($request));

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('storage_settings.check_success'),
            ]);
        } catch (ValidationException $e) {
            // 让 Inertia 正常回传字段错误
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Storage connection check failed', [
                'provider' => $data->provider ?? null,
                'region' => $data->region ?? null,
                'endpoint' => $data->endpoint ?? null,
                'bucket' => $data->bucket ?? null,
                'exception' => $e,
            ]);
            $message = __('storage_settings.validation_failed');

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => $message,
            ]);

            return back()->withErrors([
                'secret' => $message,
            ]);
        }

        return back();
    }

    /**
     * 把待检测表单参数临时组装为存储配置模型。
     */
    private function profileFromData(FormCheckStorageSettingData $data): StorageProfile
    {
        return new StorageProfile([
            'driver' => 's3',
            'provider' => $data->provider,
            'status' => 'active',
            'access_key' => $data->key,
            'secret_key' => $data->secret,
            'bucket' => $data->bucket,
            'region' => $data->region,
            'endpoint' => $data->endpoint,
            'public_url' => $data->url,
            'force_path_style' => $data->provider === StorageProvider::Minio->value,
            'signature_version' => 's3v4',
        ]);
    }
}
