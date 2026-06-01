<?php

namespace App\Actions\StorageSetting\StorageProfile;

use App\Data\StorageSetting\FormCreateStorageProfileData;
use App\Enums\StorageDriver;
use App\Enums\StorageProfileStatus;
use App\Enums\StorageProvider;
use App\Models\StorageProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 创建新的存储配置。
 *
 * 注意：创建时不会自动校验连接，用户需要时可在表单中点击“检测连接”单独验证。
 */
class CreateStorageProfileAction
{
    use AsAction;

    /**
     * 保存新的 S3 兼容存储配置。
     */
    public function handle(FormCreateStorageProfileData $data): StorageProfile
    {
        return StorageProfile::query()->create([
            'name' => $data->name,
            'driver' => StorageDriver::S3,
            'provider' => $data->provider,
            'status' => StorageProfileStatus::Active,
            'region' => $data->region,
            'endpoint' => $data->endpoint,
            'bucket' => $data->bucket,
            'access_key' => $data->key,
            'secret_key' => $data->secret,
            'public_url' => $data->url,
            'force_path_style' => $data->provider === StorageProvider::Minio->value,
            'signature_version' => 's3v4',
        ]);
    }

    /**
     * 接收新增存储配置表单并返回配置列表。
     */
    public function asController(Request $request): RedirectResponse
    {
        $data = FormCreateStorageProfileData::from($request);
        $this->handle($data);

        return redirect()->route('admin.storage.show');
    }
}
