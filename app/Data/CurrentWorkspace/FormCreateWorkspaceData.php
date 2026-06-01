<?php

namespace App\Data\CurrentWorkspace;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 创建工作区表单数据。
 * 来自 resources/js/pages/currentWorkspace/Create.vue 的新增表单提交，后端用它做校验并写入当前工作区相关记录。
 */
class FormCreateWorkspaceData extends Data
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $logo_id = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:30'],
            'slug' => ['required', 'string', 'max:50', Rule::unique('workspaces', 'slug')],
            'logo_id' => ['nullable', 'string'],
        ];
    }
}
