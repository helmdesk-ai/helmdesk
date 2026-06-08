<?php

namespace App\Data\Inbox;

use App\Actions\Reception\AppendTeammateMessageAction;
use App\Enums\ReplyAssistantMode;
use App\Enums\ReplyPolishTone;
use Closure;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 收件箱 AI 回复助手表单数据，用于校验帮写或改写请求。
 */
class FormPolishInboxReplyData extends Data
{
    /**
     * 承载助手模式、可选文本、模型、风格和可选引用消息。
     */
    public function __construct(
        public ReplyAssistantMode $mode,
        public ReplyPolishTone $tone,
        public ?string $content = null,
        public ?string $quoted_message_id = null,
    ) {}

    /**
     * 返回 AI 回复助手表单校验规则。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(array_map(static fn (ReplyAssistantMode $mode): string => $mode->value, ReplyAssistantMode::cases()))],
            'tone' => ['required', Rule::in(array_map(static fn (ReplyPolishTone $tone): string => $tone->value, ReplyPolishTone::cases()))],
            'content' => [
                'bail',
                Rule::requiredIf(static fn (): bool => request()->input('mode') === ReplyAssistantMode::Rewrite->value),
                'nullable',
                'string',
                'max:'.AppendTeammateMessageAction::MAX_CONTENT_LENGTH,
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (request()->input('mode') === ReplyAssistantMode::Rewrite->value && trim((string) $value) === '') {
                        $fail(__('validation.required', ['attribute' => $attribute]));
                    }
                },
            ],
            'quoted_message_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
