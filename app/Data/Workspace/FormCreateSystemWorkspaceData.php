<?php

namespace App\Data\Workspace;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 创建系统工作区表单数据。
 * 来自 resources/js/pages/admin/workspace/* 的新增表单提交，后端用它做校验并写入工作区相关记录。
 */
class FormCreateSystemWorkspaceData extends Data
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $logo_id,
        public string $owner_id,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:30'],
            'slug' => ['required', 'string', 'max:50', Rule::unique('workspaces', 'slug')],
            'logo_id' => ['nullable', 'string'],
            'owner_id' => ['required', 'string', Rule::exists('users', 'id')],
        ];
    }
}
