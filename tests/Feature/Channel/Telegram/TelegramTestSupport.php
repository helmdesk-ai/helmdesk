<?php

use App\Enums\AiModelPurpose;
use App\Models\AiModel;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use Illuminate\Support\Str;

// Telegram 渠道测试共享夹具：被 telegram 各测试文件 require_once 引入，函数存在性保护避免重复声明。

if (! function_exists('createTelegramTestModel')) {
    /**
     * Seed 一套全局可用的接待 AI 模型（reception_chat + background_task），让渠道 AI 可用性判定为 true。
     *
     * 接待方案不再引用具体模型：渠道按 reception_chat 用途从全局池判断 AI 可用。
     */
    function createTelegramTestModel(): AiModel
    {
        $provider = makeUsableAiProvider();
        $model = makeAiModel(AiModelPurpose::ReceptionChat, $provider);
        makeAiModel(AiModelPurpose::BackgroundTask, $provider);

        return $model;
    }
}

if (! function_exists('createTelegramDeployablePlanVersion')) {
    /**
     * 创建一个可部署到渠道的接待方案版本（同时 seed 全局可用接待模型）。
     */
    function createTelegramDeployablePlanVersion(bool $withoutAutoMessages = false): ReceptionPlanVersion
    {
        createTelegramTestModel();
        $plan = ReceptionPlan::factory()->create([
            'name' => 'TG 接待方案-'.Str::lower(Str::random(6)),
        ]);

        $factory = ReceptionPlanVersion::factory()->for($plan, 'plan');

        if ($withoutAutoMessages) {
            $factory = $factory->withoutAutoMessages();
        }

        return $factory->create();
    }
}
