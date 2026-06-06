<?php

namespace Database\Factories;

use App\Models\SystemContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<SystemContext>
 */
class SystemContextFactory extends Factory
{
    protected $model = SystemContext::class;

    /**
     * 返回单租户上下文的默认字段。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'id' => 'single',
            'name' => $name,
            'slug' => 'admin',
            'logo_id' => null,
            'owner_id' => null,
        ];
    }

    /**
     * 创建内存中的单租户上下文实例。
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return $this->make($attributes, $parent);
    }
}
