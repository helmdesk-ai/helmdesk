<?php

namespace Database\Factories;

use App\Data\Reception\AutoMessagesConfigData;
use App\Data\Reception\ReceptionMessageTranslationConfigData;
use App\Data\Reception\ReceptionStrategyConfigData;
use App\Models\ReceptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceptionPlan>
 */
class ReceptionPlanFactory extends Factory
{
    /**
     * Plan 草稿的默认状态：含基本人设、空能力 / 工具数组、最小接待 / 任务配置。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' 接待方案',
            'description' => fake()->sentence(),
            'persona_config' => [
                'display_name' => fake()->firstName().' 助手',
                'tone' => fake()->randomElement(['professional', 'friendly', 'concise']),
            ],
            'global_instructions' => fake()->paragraph(),
            'reception_config' => [
                'default_model' => null,
            ],
            'task_config' => [
                'default_model' => null,
            ],
            'capabilities' => [],
            'knowledge_base_ids' => [],
            'always_on_tools' => [],
            'strategy_config' => ReceptionStrategyConfigData::defaultConfig(),
            'auto_messages_config' => AutoMessagesConfigData::DEFAULT_CONFIG,
            'translation_config' => ReceptionMessageTranslationConfigData::DEFAULT_CONFIG,
        ];
    }
}
