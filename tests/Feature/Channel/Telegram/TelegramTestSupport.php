<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\Workspace;
use Illuminate\Support\Str;

// Telegram 渠道测试共享夹具：被 telegram 各测试文件 require_once 引入，函数存在性保护避免重复声明。

if (! function_exists('createTelegramTestModel')) {
    /**
     * 创建一个工作区内可用的 LLM 模型，供接待方案版本部署。
     */
    function createTelegramTestModel(Workspace $workspace): AiModel
    {
        $provider = AiProvider::query()->create([
            'workspace_id' => $workspace->id,
            'brand' => 'custom-openai',
            'slug' => 'tg-provider-'.Str::lower(Str::random(6)),
            'name' => 'Test Provider',
            'protocol' => 'openai',
            'credentials' => ['api_key' => 'test-key'],
            'credential_fields' => [['field' => 'api_key', 'label' => 'API Key', 'required' => true, 'secret' => true]],
            'is_builtin' => false,
            'sort_order' => 0,
        ]);

        return AiModel::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Channel Model',
            'model_id' => 'gpt-4.1-mini',
            'type' => 'llm',
            'is_active' => true,
            'is_builtin' => false,
            'sort_order' => 0,
        ]);
    }
}

if (! function_exists('createTelegramDeployablePlanVersion')) {
    /**
     * 创建一个可部署到渠道的接待方案版本。
     */
    function createTelegramDeployablePlanVersion(Workspace $workspace, bool $withoutAutoMessages = false): ReceptionPlanVersion
    {
        $model = createTelegramTestModel($workspace);
        $plan = ReceptionPlan::factory()->for($workspace)->create([
            'name' => 'TG 接待方案-'.Str::lower(Str::random(6)),
        ]);

        $factory = ReceptionPlanVersion::factory()
            ->for($plan, 'plan')
            ->withReceptionModel($model->id);

        if ($withoutAutoMessages) {
            $factory = $factory->withoutAutoMessages();
        }

        return $factory->create();
    }
}
