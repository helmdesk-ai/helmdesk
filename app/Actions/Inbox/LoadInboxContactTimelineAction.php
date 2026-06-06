<?php

namespace App\Actions\Inbox;

use App\Actions\Contact\ShowContactConversationTimelineAction;
use App\Data\Contact\ContactStitchedTimelineData;
use App\Enums\ConversationTimelineEntryType;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 加载收件箱联系人会话时间线窗口。
 */
class LoadInboxContactTimelineAction
{
    use AsAction;

    /**
     * 创建联系人时间线窗口加载动作。
     */
    public function __construct(
        private readonly ShowContactConversationTimelineAction $timeline,
    ) {}

    /**
     * 加载联系人时间线窗口数据。
     */
    public function handle(
        Contact $contact,
        int $perPage = 50,
        ?User $viewer = null,
        ?string $before = null,
        ?string $after = null,
        ?ConversationTimelineEntryType $anchorType = null,
        ?string $anchorId = null,
    ): ContactStitchedTimelineData {
        return $this->timeline->handle(
            contact: $contact,
            perPage: $perPage,
            viewer: $viewer,
            before: $before,
            after: $after,
            anchorType: $anchorType,
            anchorId: $anchorId,
        );
    }

    /**
     * 处理收件箱联系人时间线窗口请求。
     */
    public function asController(Request $request, string $contactId): JsonResponse
    {
        $contact = Contact::query()
            ->findOrFail($contactId);

        $validated = $request->validate([
            'before' => ['nullable', 'string'],
            'after' => ['nullable', 'string'],
            'anchor_type' => ['nullable', 'required_with:anchor_id', Rule::in(array_column(ConversationTimelineEntryType::cases(), 'value'))],
            'anchor_id' => ['nullable', 'required_with:anchor_type', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $timeline = $this->handle(
            contact: $contact,
            perPage: (int) ($validated['per_page'] ?? 50),
            viewer: $request->user(),
            before: $validated['before'] ?? null,
            after: $validated['after'] ?? null,
            anchorType: isset($validated['anchor_type']) ? ConversationTimelineEntryType::from($validated['anchor_type']) : null,
            anchorId: $validated['anchor_id'] ?? null,
        );

        return response()->json(['timeline' => $timeline]);
    }
}
