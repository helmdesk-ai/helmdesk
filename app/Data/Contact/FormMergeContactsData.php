<?php

namespace App\Data\Contact;

use Spatie\LaravelData\Data;

/**
 * 合并Contacts表单数据。
 * 来自 resources/js/pages/contacts/Index.vue、Trash.vue 和 ContactDetailDrawer.vue 的操作表单或弹窗提交，后端用它校验本次合并动作。
 */
class FormMergeContactsData extends Data
{
    public function __construct(
        public string $target_contact_id,
        public string $merged_contact_id,
    ) {}

    public static function rules(): array
    {
        return [
            'target_contact_id' => ['required', 'string'],
            'merged_contact_id' => ['required', 'string'],
        ];
    }
}
