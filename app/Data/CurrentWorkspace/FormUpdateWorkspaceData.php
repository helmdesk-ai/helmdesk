<?php

namespace App\Data\CurrentWorkspace;

use App\Data\WorkspaceUserContextData;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 更新工作区表单数据。
 * 来自 resources/js/pages/currentWorkspace/Index.vue 的编辑表单提交，后端用它校验并保存当前工作区配置。
 */
class FormUpdateWorkspaceData extends Data
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
        $context = WorkspaceUserContextData::tryFromRequest(request());
        $workspaceId = $context?->workspaceId();

        return [
            'name' => ['required', 'string', 'max:30'],
            'slug' => ['required', 'string', 'max:50', Rule::unique('workspaces', 'slug')->ignore($workspaceId)],
            'logo_id' => ['nullable', 'string'],
        ];
    }
}
