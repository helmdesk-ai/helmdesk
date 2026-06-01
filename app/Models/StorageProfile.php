<?php

namespace App\Models;

use App\Enums\StorageDriver;
use App\Enums\StorageProfileStatus;
use App\Enums\StorageProvider;
use Database\Factories\StorageProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $name
 * @property \App\Enums\StorageDriver $driver
 * @property \App\Enums\StorageProvider|null $provider
 * @property \App\Enums\StorageProfileStatus $status
 * @property string|null $access_key
 * @property string|null $secret_key
 * @property string|null $session_token
 * @property string|null $bucket
 * @property string|null $region
 * @property string|null $endpoint
 * @property string|null $public_url
 * @property string|null $upload_endpoint
 * @property string|null $download_endpoint
 * @property bool $force_path_style
 * @property string $signature_version
 * @property int|null $max_upload_size
 * @property array|null $allowed_mime_types
 * @property array|null $metadata
 * @property mixed $use_factory
 *
 * @method static \Database\Factories\StorageProfileFactory<self> factory($count = null, $state = [])
 */
class StorageProfile extends Model
{
    /**
     * 存储配置模型，保存 S3 兼容对象存储的连接参数和启用状态。
     */
    /** @use HasFactory<StorageProfileFactory> */
    use HasFactory, HasUlids;

    protected $table = 'storage_profiles';

    protected $guarded = [];

    /**
     * 返回存储配置字段类型转换规则。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => StorageDriver::class,
            'provider' => StorageProvider::class,
            'status' => StorageProfileStatus::class,
            'access_key' => 'encrypted',
            'secret_key' => 'encrypted',
            'session_token' => 'encrypted',
            'force_path_style' => 'boolean',
            'max_upload_size' => 'integer',
            'allowed_mime_types' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * 返回临时文件系统使用的 S3 磁盘配置。
     *
     * @return array<string, mixed>
     */
    public function s3FilesystemConfig(): array
    {
        return $this->withoutNullValues([
            'driver' => 's3',
            'key' => $this->access_key,
            'secret' => $this->secret_key,
            'token' => $this->session_token,
            'region' => $this->region ?: 'us-east-1',
            'bucket' => $this->bucket,
            'endpoint' => $this->endpoint,
            'url' => $this->public_url,
            'use_path_style_endpoint' => $this->force_path_style || $this->provider === StorageProvider::Minio,
            'throw' => true,
        ]);
    }

    /**
     * 返回 AWS SDK S3Client 使用的连接配置。
     *
     * @return array<string, mixed>
     */
    public function s3ClientConfig(): array
    {
        return $this->withoutNullValues([
            'version' => 'latest',
            'region' => $this->region ?: 'us-east-1',
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => $this->force_path_style || $this->provider === StorageProvider::Minio,
            'signature_version' => $this->signature_version === 's3v4' ? 'v4' : $this->signature_version,
            'credentials' => $this->withoutNullValues([
                'key' => (string) $this->access_key,
                'secret' => (string) $this->secret_key,
                'token' => $this->session_token,
            ]),
        ]);
    }

    /**
     * 过滤配置数组中的 null 值，避免传给底层适配器。
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withoutNullValues(array $config): array
    {
        return array_filter($config, static fn (mixed $value): bool => $value !== null);
    }
}
