<?php

namespace App\Data\Teammate;

use App\Enums\WorkspaceRole;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 创建客服成员表单数据。
 * 来自 resources/js/pages/teammate/List.vue、Create.vue、Edit.vue 的新增表单提交，后端用它做校验并写入客服成员相关记录。
 */
class FormCreateTeammateData extends Data
{
    public function __construct(
        public string $user_id,
        public ?string $nickname,
        public WorkspaceRole $role,
    ) {}

    public static function rules(): array
    {
        return [
            'user_id' => ['required', 'string', Rule::exists('users', 'id')],
            'nickname' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)->only(WorkspaceRole::assignableCases())],
        ];
    }
}
