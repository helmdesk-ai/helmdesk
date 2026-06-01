<?php

namespace Database\Factories;

use App\Data\Reception\AutoMessagesConfigData;
use App\Data\Reception\ReceptionMessageTranslationConfigData;
use App\Data\Reception\ReceptionStrategyConfigData;
use App\Enums\ReceptionPlanVersionStatus;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceptionPlanVersion>
 */
class ReceptionPlanVersionFactory extends Factory
{
    /**
     * 版本的默认状态：published、version_number=1、snapshot/compiled 最小可解析快照。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $snapshot = [
            'name' => '默认接待方案',
            'persona_config' => ['display_name' => '默认助手', 'tone' => 'concise'],
            'global_instructions' => fake()->sentence(),
            'reception_config' => ['default_model' => null],
            'task_config' => ['default_model' => null],
            'capabilities' => [],
            'knowledge_base_ids' => [],
            'always_on_tools' => [],
            'strategy_config' => ReceptionStrategyConfigData::defaultConfig(),
            'auto_messages_config' => AutoMessagesConfigData::DEFAULT_CONFIG,
            'translation_config' => ReceptionMessageTranslationConfigData::DEFAULT_CONFIG,
        ];

        return [
            'reception_plan_id' => ReceptionPlan::factory(),
            'version_number' => 1,
            'description' => null,
            'snapshot_config' => $snapshot,
            'compiled_config' => [
                'reception_agent' => ['instruction' => '你是一名客服助手。'],
                'reception_config' => ['default_model' => null],
                'task_config' => ['default_model' => null],
                'service_scenarios' => [],
                'knowledge_bases' => [],
                'mcp_tools' => [],
            ],
            'status' => ReceptionPlanVersionStatus::Published,
            'published_at' => now(),
            'published_by_user_id' => null,
        ];
    }

    /**
     * 归档状态，仍可被历史会话解析但不允许新部署指向。
     */
    public function archived(): static
    {
        return $this->state([
            'status' => ReceptionPlanVersionStatus::Archived,
        ]);
    }

    /**
     * 显式让版本的接待 / 任务默认模型指向指定 ai_model_id；用于让 AiModelResolver
     * 把版本判定为"可用"，避免测试里反复手工拼 snapshot / compiled 结构。
     */
    public function withReceptionModel(string $aiModelId): static
    {
        return $this->state(function (array $attributes) use ($aiModelId): array {
            $snapshot = $attributes['snapshot_config'] ?? [];
            $compiled = $attributes['compiled_config'] ?? [];

            $snapshot['reception_config'] = array_merge(
                $snapshot['reception_config'] ?? [],
                ['default_model' => ['ai_model_id' => $aiModelId]],
            );
            $snapshot['task_config'] = array_merge(
                $snapshot['task_config'] ?? [],
                ['default_model' => ['ai_model_id' => $aiModelId]],
            );

            $compiled['reception_config'] = array_merge(
                $compiled['reception_config'] ?? [],
                ['default_model' => ['ai_model_id' => $aiModelId]],
            );
            $compiled['task_config'] = array_merge(
                $compiled['task_config'] ?? [],
                ['default_model' => ['ai_model_id' => $aiModelId]],
            );

            return [
                'snapshot_config' => $snapshot,
                'compiled_config' => $compiled,
            ];
        });
    }

    /**
     * 关闭版本快照中的自动回复，供不关心自动回复副作用的协议级测试使用。
     */
    public function withoutAutoMessages(): static
    {
        return $this->state(function (array $attributes): array {
            $snapshot = $attributes['snapshot_config'] ?? [];
            $snapshot['auto_messages_config'] = [
                'ai_welcome' => ['enabled' => false, 'message' => null],
                'teammate_joined' => ['enabled' => false, 'message' => null],
                'teammate_transferred' => ['enabled' => false, 'message' => null],
            ];

            return [
                'snapshot_config' => $snapshot,
            ];
        });
    }
}
