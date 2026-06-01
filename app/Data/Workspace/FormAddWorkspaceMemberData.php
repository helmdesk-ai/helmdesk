<?php

namespace App\Data\Workspace;

use App\Enums\WorkspaceRole;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 添加工作区成员表单数据。
 * 来自 resources/js/pages/admin/workspace/* 的操作表单或弹窗提交，后端用它校验本次添加动作。
 */
class FormAddWorkspaceMemberData extends Data
{
    public function __construct(
        public string $user_id,
        public WorkspaceRole $role,
    ) {}

    public static function rules(): array
    {
        return [
            'user_id' => ['required', 'string', Rule::exists('users', 'id')],
            'role' => ['required', Rule::enum(WorkspaceRole::class)->only(WorkspaceRole::assignableCases())],
        ];
    }
}
