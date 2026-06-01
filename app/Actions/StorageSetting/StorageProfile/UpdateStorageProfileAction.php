<?php

namespace App\Actions\StorageSetting\StorageProfile;

use App\Data\StorageSetting\FormUpdateStorageProfileData;
use App\Models\StorageProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新存储配置及可选凭据。
 *
 * 仅允许修改名称、自定义域名和访问凭据；
 * key+secret 必须成对提交，留空表示保持不变。
 */
class UpdateStorageProfileAction
{
    use AsAction;

    /**
     * 更新存储配置可编辑字段和可选凭据。
     */
    public function handle(StorageProfile $profile, FormUpdateStorageProfileData $data): void
    {
        $update = [
            'name' => $data->name,
            'public_url' => $data->url,
        ];

        $key = filled($data->key) ? $data->key : null;
        $secret = filled($data->secret) ? $data->secret : null;
        $keyProvided = filled($key);
        $secretProvided = filled($secret);
        if ($keyProvided || $secretProvided) {
            if (! $keyProvided || ! $secretProvided) {
                throw ValidationException::withMessages([
                    'secret' => __('storage_settings.profile_credentials_pair_required'),
                ]);
            }

            $update['access_key'] = $key;
            $update['secret_key'] = $secret;
        }

        $profile->update($update);
    }

    /**
     * 接收编辑存储配置表单并返回配置列表。
     */
    public function asController(Request $request, StorageProfile $profile): RedirectResponse
    {
        $data = FormUpdateStorageProfileData::validateAndCreate($request->all());
        $this->handle($profile, $data);

        return redirect()->route('admin.storage.show');
    }
}
