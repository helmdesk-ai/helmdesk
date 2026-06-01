<?php

declare(strict_types=1);

use App\Models\ReceptionPlanVersion;

// 校验轻量任务（打标签/摘要）的模型解析：优先任务智能体配置，未配置时回退接待模型。

it('prefers task_config when it has a model', function (): void {
    $version = new ReceptionPlanVersion;
    $version->compiled_config = [
        'reception_config' => ['default_model' => ['ai_model_id' => 'reception-model']],
        'task_config' => ['default_model' => ['ai_model_id' => 'task-model']],
    ];

    expect($version->resolveTaskAgentConfig())
        ->toBe(['default_model' => ['ai_model_id' => 'task-model']]);
});

it('prefers task_config when it only has candidates', function (): void {
    $version = new ReceptionPlanVersion;
    $version->compiled_config = [
        'reception_config' => ['default_model' => ['ai_model_id' => 'reception-model']],
        'task_config' => ['model_candidates' => [['ai_model_id' => 'task-candidate', 'priority' => 0]]],
    ];

    expect($version->resolveTaskAgentConfig())
        ->toBe(['model_candidates' => [['ai_model_id' => 'task-candidate', 'priority' => 0]]]);
});

it('falls back to reception_config when task_config has no model', function (): void {
    $version = new ReceptionPlanVersion;
    $version->compiled_config = [
        'reception_config' => ['default_model' => ['ai_model_id' => 'reception-model']],
        'task_config' => ['default_model' => ['ai_model_id' => null], 'model_candidates' => []],
    ];

    expect($version->resolveTaskAgentConfig())
        ->toBe(['default_model' => ['ai_model_id' => 'reception-model']]);
});

it('returns empty array when neither slot is configured', function (): void {
    $version = new ReceptionPlanVersion;
    $version->compiled_config = [];

    expect($version->resolveTaskAgentConfig())->toBe([]);
});
