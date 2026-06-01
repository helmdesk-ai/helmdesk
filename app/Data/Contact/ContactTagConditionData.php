<?php

namespace App\Data\Contact;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * 联系人标签条件数据。
 * 由后端组装后传给 resources/js/pages/contacts/Index.vue、Trash.vue 和 ContactDetailDrawer.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class ContactTagConditionData extends Data
{
    public function __construct(
        public string $tag_id,
        public ?Carbon $tagged_after = null,
        public ?Carbon $tagged_before = null,
        public ?string $assigned_by_user_id = null,
        public ?string $source = null,
    ) {}

    public function hasAssignmentConstraints(): bool
    {
        return $this->tagged_after !== null
            || $this->tagged_before !== null
            || $this->assigned_by_user_id !== null
            || $this->source !== null;
    }
}
