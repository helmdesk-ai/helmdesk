<?php

namespace App\Services\Channel;

use App\Actions\Channel\Web\DeleteWebChannelAction;
use App\Actions\Channel\Web\UpdateWebChannelBasicAction;
use App\Actions\Channel\Web\UpdateWebChannelEmbedAction;
use App\Actions\Channel\Web\UpdateWebChannelWidgetAction;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\SystemContext;

/**
 * 网站渠道解析服务：封装渠道查找等跨 Action 复用的渠道层操作。
 *
 * 原先这些逻辑以静态辅助方法分散在 UpdateWebChannelBasicAction 中，
 * 导致同领域其他 Action 直接耦合另一个 Action 的内部方法。
 * 抽取到 Service 后各 Action 通过 DI 注入，符合 Action 间调用应使用 ::run() 或共享 Service 的约定。
 *
 * @see UpdateWebChannelBasicAction
 * @see UpdateWebChannelEmbedAction
 * @see UpdateWebChannelWidgetAction
 * @see DeleteWebChannelAction
 */
class WebChannelResolutionService
{
    /**
     * 查找当前系统内的网站渠道，不存在时抛出 404。
     */
    public function findSystemChannel(SystemContext $systemContext, string $channelId): Channel
    {
        return Channel::query()
            ->where('type', ChannelType::Web)
            ->findOrFail($channelId);
    }
}
