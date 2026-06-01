<?php

namespace App\Data\Teammate;

use App\Enums\UserOnlineStatus;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 更新客服成员Online状态表单数据。
 * 来自 resources/js/pages/teammate/List.vue、Create.vue、Edit.vue 的编辑表单提交，后端用它校验并保存客服成员配置。
 */
class FormUpdateTeammateOnlineStatusData extends Data
{
    public function __construct(
        public UserOnlineStatus $online_status,
    ) {}

    public static function rules(): array
    {
        return [
            'online_status' => ['required', 'integer', Rule::enum(UserOnlineStatus::class)],
        ];
    }
}
