<?php

namespace App\Jobs\Conversation;

use App\Actions\Conversation\GenerateConversationSubjectAction;
use App\Models\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 会话主题生成队列任务。
 */
class GenerateConversationSubjectJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    /**
     * 创建会话主题生成任务。
     */
    public function __construct(public readonly string $conversationId) {}

    /**
     * 执行会话主题生成。
     */
    public function handle(GenerateConversationSubjectAction $action): void
    {
        $action->handle(Conversation::query()->findOrFail($this->conversationId));
    }

    /**
     * 记录主题生成最终失败原因。
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('GenerateConversationSubjectJob failed.', [
            'conversation_id' => $this->conversationId,
            'reason' => $exception->getMessage(),
        ]);
    }
}
