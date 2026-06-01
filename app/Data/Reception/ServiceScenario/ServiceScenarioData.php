<?php

namespace App\Data\Reception\ServiceScenario;

use Spatie\LaravelData\Data;

/**
 * 服务场景展示数据。
 * 由 ShowReceptionPlanIndexPageAction 在补全当前选中 plan 时组装，下发给
 * resources/js/pages/reception/plans/Index.vue 的服务场景分段与编辑 Dialog 使用。
 * 服务场景没有独立数据库行，以 reception_plans.capabilities JSON 数组的元素形态存在。
 */
class ServiceScenarioData extends Data
{
    public function __construct(
        public string $name,
        public string $description,
        public string $instructions,
    ) {}

    /**
     * 从 capabilities JSON 数组的单个元素构造展示 Data。
     * 写入侧 UpdateReceptionPlanAction::buildServiceScenarios 已保证三个字段为字符串，
     * 这里按强类型访问，结构异常应在写入侧定位修复。
     *
     * @param  array{name: string, description: string, instructions: string}  $raw
     */
    public static function fromRaw(array $raw): self
    {
        return new self(
            name: $raw['name'],
            description: $raw['description'],
            instructions: $raw['instructions'],
        );
    }
}
