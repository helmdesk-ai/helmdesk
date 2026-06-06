<?php

namespace App\Services\Reception;

/**
 * 接待方案服务场景预置模板。
 *
 * @phpstan-type ScenarioTemplate array{
 *     code: string,
 *     name: string,
 *     description: string,
 *     instructions: string,
 * }
 */
final class ServiceScenarioTemplates
{
    /**
     * 返回所有预置模板，顺序即前端列表展示顺序。
     *
     * @return list<ScenarioTemplate>
     */
    public static function all(): array
    {
        return [
            self::orderQuery(),
            self::faq(),
            self::aftersale(),
            self::logistics(),
        ];
    }

    /**
     * 订单查询模板：处理订单状态、订单详情、订单列表类问题。
     *
     * @return ScenarioTemplate
     */
    private static function orderQuery(): array
    {
        return [
            'code' => 'order_query',
            'name' => '订单查询',
            'description' => '处理订单状态、订单详情、订单列表类问题。',
            'instructions' => <<<'PROMPT'
你是订单查询专员。当接待 AI 派发订单查询任务时：

1. 先确认访客身份与订单号是否齐全；订单号缺失时，请说明需要的最小信息（订单号或下单手机号）
2. 调用业务工具查询订单详情；按订单号 / 时间 / 状态 / 金额的顺序结构化输出
3. 订单不存在时明确告知，并建议访客核对订单号
4. 输出保持简洁，使用 Markdown 列表；你的回复会被接待 AI 转述给访客，不要包含寒暄

如遇业务工具失败，请直接说明"订单查询服务暂时不可用"，由接待 AI 决定后续兜底。
PROMPT,
        ];
    }

    /**
     * 常见问题模板：基于知识库回答政策、流程、产品说明类问题。
     *
     * @return ScenarioTemplate
     */
    private static function faq(): array
    {
        return [
            'code' => 'faq',
            'name' => '常见问题',
            'description' => '基于知识库回答政策、流程、产品说明等问题。',
            'instructions' => <<<'PROMPT'
你是常见问题专员，负责基于知识库回答访客的政策、流程、产品说明等问题。

1. 收到问题后，先用知识库检索工具查找相关条目
2. 命中后用知识库内容组织答案，必要时引用文档标题；不要凭空发挥
3. 知识库未命中时，明确告知"暂未找到对应资料"，避免编造
4. 回复保持简洁、口语化、客服风格；你的回复会被接待 AI 转述给访客
PROMPT,
        ];
    }

    /**
     * 售后服务模板：处理退换货、维修、投诉等需要谨慎的售后场景。
     *
     * @return ScenarioTemplate
     */
    private static function aftersale(): array
    {
        return [
            'code' => 'aftersale',
            'name' => '售后处理',
            'description' => '处理退换货、维修、投诉等售后场景；高风险动作通知接待 AI 转人工。',
            'instructions' => <<<'PROMPT'
你是售后服务专员，负责处理退换货、维修、投诉类问题。

1. 先收集必要信息：订单号、问题类型（退货 / 换货 / 维修 / 投诉）、问题描述
2. 涉及退款、改地址、取消订单等修改类高风险动作时，生成结构化结论"建议转人工处理"回传，由接待 AI 决定是否转人工
3. 仅可读类问题（如查询售后政策、退货进度）可直接基于工具与知识库回答
4. 措辞稳重克制，避免承诺具体处理结果；你的回复会被接待 AI 转述给访客
PROMPT,
        ];
    }

    /**
     * 物流查询模板：处理快递单号、物流进度、预计送达类问题。
     *
     * @return ScenarioTemplate
     */
    private static function logistics(): array
    {
        return [
            'code' => 'logistics',
            'name' => '物流查询',
            'description' => '处理快递单号、物流进度、预计送达类问题。',
            'instructions' => <<<'PROMPT'
你是物流查询专员，负责回答访客关于快递单号、物流进度与预计送达时间的问题。

1. 缺少订单号或运单号时，先提示访客提供
2. 命中物流工具后，按"承运商 / 运单号 / 当前状态 / 预计送达"四段结构化输出
3. 物流信息异常（超时未更新、单号错误）时，建议访客核对单号；必要时建议联系人工
4. 你的回复会被接待 AI 转述给访客，保持简洁、专业
PROMPT,
        ];
    }
}
