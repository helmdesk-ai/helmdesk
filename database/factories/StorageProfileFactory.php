<?php

namespace Database\Factories;

use App\Enums\StorageDriver;
use App\Enums\StorageProfileStatus;
use App\Enums\StorageProvider;
use App\Models\StorageProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorageProfile>
 */
class StorageProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' storage',
            'driver' => StorageDriver::S3,
            'provider' => StorageProvider::Minio,
            'status' => StorageProfileStatus::Active,
            'bucket' => 'bucket',
            'region' => 'us-east-1',
            'endpoint' => 'http://minio:9000',
            'access_key' => 'key',
            'secret_key' => 'secret',
            'force_path_style' => true,
            'signature_version' => 's3v4',
        ];
    }

    public function local(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Local private storage',
            'driver' => StorageDriver::Local,
            'provider' => null,
            'bucket' => null,
            'region' => null,
            'endpoint' => null,
            'access_key' => null,
            'secret_key' => null,
            'force_path_style' => false,
        ]);
    }
}
