<?php

namespace App\Jobs\Contact;

use App\Actions\Contact\GenerateContactAiSummaryAction;
use App\Models\Contact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 联系人 AI 摘要生成队列任务，按联系人 ID 串行刷新 contacts.ai_context.summary。
 */
class GenerateContactAiSummaryJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * 创建联系人 AI 摘要生成任务。
     */
    public function __construct(
        public readonly string $contactId,
    ) {}

    /**
     * 同一联系人串行执行，拿不到锁的任务释放回队列稍后重试。
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->contactId))
                ->releaseAfter(10)
                ->expireAfter(180),
        ];
    }

    /**
     * 执行联系人 AI 摘要生成。
     */
    public function handle(GenerateContactAiSummaryAction $action): void
    {
        $action->handle(Contact::query()->findOrFail($this->contactId));
    }

    /**
     * 记录联系人摘要生成最终失败原因。
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('GenerateContactAiSummaryJob failed.', [
            'contact_id' => $this->contactId,
            'reason' => $exception->getMessage(),
        ]);
    }
}
