<?php

namespace App\Data\Inbox;

use App\Enums\ReceptionLanguage;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 更新会话访客语言表单数据。
 * 来自收件箱联系人面板的语言选择器提交。
 */
class FormUpdateConversationVisitorLocaleData extends Data
{
    /**
     * 访客语言字段。
     */
    public function __construct(
        public ReceptionLanguage $visitor_locale = ReceptionLanguage::ChineseSimplified,
    ) {}

    /**
     * 返回访客语言表单校验规则。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'visitor_locale' => ['required', Rule::enum(ReceptionLanguage::class)],
        ];
    }
}
