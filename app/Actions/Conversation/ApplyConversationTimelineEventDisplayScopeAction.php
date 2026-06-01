<?php

namespace App\Actions\Conversation;

use App\Enums\ConversationEventType;
use Illuminate\Database\Query\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 限制会话时间线事件查询只返回客服需要看到的活动记录。
 */
class ApplyConversationTimelineEventDisplayScopeAction
{
    use AsAction;

    /**
     * 应用客服时间线事件展示范围。
     */
    public function handle(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->whereNotIn('type', [
                    ConversationEventType::ReceptionTurnStarted->value,
                    ConversationEventType::ReceptionTurnEnded->value,
                    ConversationEventType::ReceptionToolCalled->value,
                ])
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('type', ConversationEventType::ReceptionTurnEnded->value)
                        ->whereIn('payload->ended_by', ['timeout', 'error', 'max_iterations']);
                })
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('type', ConversationEventType::ReceptionToolCalled->value)
                        ->where(function (Builder $query): void {
                            $query
                                ->where('payload->tool', 'dispatch_task')
                                ->orWhere(function (Builder $query): void {
                                    $query
                                        ->where('payload->tool', 'cancel_task')
                                        ->where('payload->result', 'cancelled');
                                })
                                ->orWhere(function (Builder $query): void {
                                    $query
                                        ->where('payload->tool', 'handoff_to_human')
                                        ->where('payload->accepted', false);
                                });
                        });
                });
        });
    }
}
