<?php

namespace App\Actions\Native\Reception;

use App\Actions\Reception\LoadConversationHistoryAction;
use App\Models\Conversation;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Native bridge 入口：为接待 actor 在进程重启后从 DB 复活会话历史。
 *
 * 正常运行期 actor 持有自己累积的 ReAct 内存历史；这个 bridge 只在 actor 首次启动且
 * history 为空时调用一次，把 DB 里已有的访客 / AI / 客服文本消息按 seq_no 升序填回去，
 * 让 LLM 拿到完整对话上下文。
 */
class LoadConversationHistoryBridgeAction
{
    use AsAction;

    /**
     * 注入负责加载会话历史的业务 Action。
     */
    public function __construct(
        private readonly LoadConversationHistoryAction $loadHistory,
    ) {}

    /**
     * 按 seq_no 升序返回 visitor + ai + teammate 文本消息列表，供接待 actor 重启后复活内存历史使用。
     *
     * @param  string  $conversationId  会话 ID；找不到对应记录会抛 404
     * @param  int|null  $limit  返回上限；null / <=0 走默认 50，超过 MAX_LIMIT 截断到 200
     * @return array<int, array{id: string, role: string, content: string}>
     */
    public function handle(string $conversationId, ?int $limit = null): array
    {
        $conversation = Conversation::query()->find($conversationId);
        if ($conversation === null) {
            throw new NotFoundHttpException;
        }

        $normalizedLimit = $limit === null || $limit <= 0
            ? LoadConversationHistoryAction::DEFAULT_LIMIT
            : min($limit, LoadConversationHistoryAction::MAX_LIMIT);

        return $this->loadHistory->handle($conversation, $normalizedLimit);
    }
}
