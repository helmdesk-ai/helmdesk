<?php

namespace App\Data\Inbox;

use Spatie\LaravelData\Data;

/**
 * 收件箱联系人 AI 摘要补翻表单数据。
 * 来自右侧 AI 摘要 Tab 的视图层自动翻译请求。
 */
class FormQueueInboxContactAiSummaryTranslationData extends Data
{
    /**
     * 创建联系人摘要补翻请求数据。
     */
    public function __construct(
        public string $target_locale,
    ) {}

    /**
     * 返回表单校验规则。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'target_locale' => ['required', 'string', 'max:20'],
        ];
    }
}
