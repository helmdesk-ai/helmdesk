<?php

namespace Database\Factories;

use App\Enums\TagSource;
use App\Models\Tag;
use App\Models\TagGroup;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'workspace_id' => Workspace::factory(),
            // 标签必属于一个组；默认在标签所在 workspace 下就地建组，保证 workspace 一致。
            'tag_group_id' => fn (array $attributes) => TagGroup::factory()
                ->create(['workspace_id' => $attributes['workspace_id']])->id,
            'name' => $name,
            'normalized_name' => mb_strtolower(trim($name)),
            'color' => fake()->optional()->hexColor(),
            'description' => fake()->optional()->sentence(),
            'source' => TagSource::Manual,
            'is_locked' => false,
        ];
    }

    /**
     * 将标签归入指定标签组，并对齐到该组的 workspace。
     */
    public function forGroup(TagGroup $group): static
    {
        return $this->state(fn () => [
            'workspace_id' => $group->workspace_id,
            'tag_group_id' => $group->id,
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['is_locked' => true]);
    }
}
