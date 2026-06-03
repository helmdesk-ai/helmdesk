<?php

namespace App\Actions\Channel\Telegram;

use App\Enums\ChannelType;
use App\Enums\UserPermission;
use App\Exceptions\TelegramApiException;
use App\Models\Channel;
use App\Services\Telegram\TelegramBotApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 软删除（暂停）Telegram 渠道，并尽力从 Telegram 撤销 webhook 停止入站。
 */
class DeleteTelegramChannelAction
{
    use AsAction;

    /**
     * 注入 Telegram API 客户端。
     */
    public function __construct(
        private readonly TelegramBotApi $api,
    ) {}

    /**
     * 撤销 webhook（尽力而为）后软删除渠道。
     *
     * 撤销失败不阻断删除：渠道暂停后入站 handler 也会拒收，残留 webhook 不影响业务，仅记日志。
     */
    public function handle(Channel $channel): void
    {
        if (filled($channel->telegram_bot_token)) {
            try {
                $this->api->deleteWebhook((string) $channel->telegram_bot_token);
            } catch (TelegramApiException $e) {
                Log::warning('删除 Telegram webhook 失败，仍继续软删除渠道。', [
                    'channel_id' => (string) $channel->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        $channel->delete();
    }

    /**
     * 接收删除请求并返回列表页。
     */
    public function asController(Request $request, string $channel): RedirectResponse
    {
        Gate::authorize('user.permission', UserPermission::ChannelsDelete);

        $channelModel = Channel::query()
            ->where('type', ChannelType::Telegram)
            ->findOrFail($channel);

        $this->handle($channelModel);

        return redirect()->route('admin.manage.channels.telegram.index');
    }
}
