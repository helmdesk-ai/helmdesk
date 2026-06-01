<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 消息投递状态，标识从产生到送达接收端各阶段。
 *
 * 网站渠道：消息落库即视为 Sent（访客端靠 Mercure 拉取）。
 * Telegram 等需主动推送的外部渠道：出站消息先置 Sending，发送 Job 成功翻 Sent、失败翻 Failed；
 * 长时间停留 Sending 的消息由对账任务（telegram:reconcile-deliveries）重投兜底。
 */
enum MessageDeliveryStatus: string implements LabeledEnum
{
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';

    /**
     * 返回消息投递状态的多语言标签。
     */
    public function label(): string
    {
        return match ($this) {
            self::Sending => __('conversation.message_delivery_statuses.sending'),
            self::Sent => __('conversation.message_delivery_statuses.sent'),
            self::Failed => __('conversation.message_delivery_statuses.failed'),
        };
    }
}
