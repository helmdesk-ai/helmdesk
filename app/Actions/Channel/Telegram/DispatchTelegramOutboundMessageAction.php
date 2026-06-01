<?php

namespace App\Actions\Channel\Telegram;

use App\Enums\ChannelType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Jobs\Telegram\SendTelegramMessageJob;
use App\Models\ConversationMessage;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 判断一条新建消息是否需要投递到 Telegram，并派发出站发送任务。
 *
 * 由 ConversationMessage 的 created 钩子统一调用：这是覆盖所有出站来源（AI 回复、AI 欢迎、
 * 转人工兜底、客服回复等）的唯一入口，无需在每个产出消息的 Action 里各自处理投递。
 * 访客 / 工具消息与非 Telegram 渠道在此被快速过滤，不产生多余开销。
 */
class DispatchTelegramOutboundMessageAction
{
    use AsAction;

    /**
     * 出站消息满足条件时派发 Telegram 发送任务。
     */
    public function handle(ConversationMessage $message): void
    {
        // 仅 AI / 客服产生的内容是面向访客的出站消息。
        if (! in_array($message->role, [MessageRole::Ai, MessageRole::Teammate], true)) {
            return;
        }

        if ($message->recalled_at !== null) {
            return;
        }

        // 出站支持两类：有正文的文本消息，以及图片 / 文件媒体消息。
        $isText = $message->kind === MessageKind::Text && filled($message->content);
        $isMedia = in_array($message->kind, [MessageKind::Image, MessageKind::File], true);
        if (! $isText && ! $isMedia) {
            return;
        }

        $channel = $message->conversation?->channel;
        if ($channel?->type !== ChannelType::Telegram) {
            return;
        }

        // 出站到 Telegram 的消息在真正发出前先置 sending：落库即标 sent 会谎报投递结果，
        // 也让对账扫描（ReconcileTelegramDeliveriesAction）能识别"已落库但未送达"的消息。
        $message->update(['delivery_status' => MessageDeliveryStatus::Sending]);

        SendTelegramMessageJob::dispatch((string) $message->id)->afterCommit();
    }
}
