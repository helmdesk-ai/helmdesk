<?php

namespace App\Console\Commands;

use App\Actions\Channel\Telegram\ReconcileTelegramDeliveriesAction;
use Illuminate\Console\Command;

/**
 * 定时重投卡在 sending 的 Telegram 出站消息，兜底发送任务丢失的崩溃窗口。
 */
class ReconcileTelegramDeliveriesCommand extends Command
{
    /** @var string 命令名称和参数签名。 */
    protected $signature = 'telegram:reconcile-deliveries';

    /** @var string 命令说明。 */
    protected $description = 'Re-dispatch Telegram outbound messages stuck in the sending state.';

    /**
     * 执行 Telegram 出站投递对账。
     */
    public function handle(ReconcileTelegramDeliveriesAction $action): int
    {
        $count = $action->handle();

        $this->info("Re-dispatched {$count} stuck Telegram outbound message(s).");

        return self::SUCCESS;
    }
}
