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
 * 重投卡在 sending 的 Telegram 出站消息，兜底"已落库但发送任务丢失"的崩溃窗口。
 *
 * 这是把 conversation_messages.delivery_status 当作轻量投递台账的恢复环节：消息出站前置 sending，
 * 发送 Job 成功/失败翻 sent/failed；长时间仍是 sending 的几乎只可能是 Job 从未入队，重投即可恢复。
 * 发送 Job 自身幂等（已记录 Telegram message_id 则跳过），即便偶发重投也不会重复发送。
 */
class ReconcileTelegramDeliveriesAction
{
    use AsAction;

    /**
     * 判定"卡住"的最小停留时长（秒）。
     *
     * 远大于发送 Job 的重试窗口（tries=3 + backoff[5,30] ≈ 1 分钟内收敛），
     * 避免与仍在重试的在途 Job 竞争而造成重复发送。
     */
    public const STUCK_AFTER_SECONDS = 180;

    /** 单次最多重投数量，防止异常堆积时一次性放大。 */
    private const BATCH_LIMIT = 200;

    /**
     * 扫描并重投卡住的 Telegram 出站消息，返回本次重投数量。
     */
    public function handle(?int $stuckAfterSeconds = null): int
    {
        $threshold = now()->subSeconds($stuckAfterSeconds ?? self::STUCK_AFTER_SECONDS);

        $messages = ConversationMessage::query()
            ->where('delivery_status', MessageDeliveryStatus::Sending)
            ->whereIn('role', [MessageRole::Ai, MessageRole::Teammate])
            ->where('kind', MessageKind::Text)
            ->whereNull('recalled_at')
            ->where('created_at', '<', $threshold)
            ->whereHas('conversation.channel', fn ($query) => $query->where('type', ChannelType::Telegram))
            ->orderBy('created_at')
            ->limit(self::BATCH_LIMIT)
            ->get();

        foreach ($messages as $message) {
            SendTelegramMessageJob::dispatch((string) $message->id);
        }

        return $messages->count();
    }
}
