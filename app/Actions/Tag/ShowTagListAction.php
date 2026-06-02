<?php

namespace App\Actions\Tag;

use App\Data\EnumOptionData;
use App\Data\SystemUserContextData;
use App\Data\Tag\ListTagGroupItemData;
use App\Data\Tag\ShowListTagPagePropsData;
use App\Enums\TagScope;
use App\Models\SystemContext;
use App\Models\TagGroup;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示标签列表，按标签组（含适用维度）组织并附带各标签的会话/联系人使用量。
 */
class ShowTagListAction
{
    use AsAction;

    /**
     * 查询当前系统标签组及组内标签。
     */
    public function handle(SystemContext $systemContext): ShowListTagPagePropsData
    {
        $groups = $systemContext->tagGroups()
            ->with(['tags' => function ($query) {
                $query->withCount(['contacts', 'conversations'])->orderBy('name', 'asc');
            }])
            ->orderBy('scope')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return new ShowListTagPagePropsData(
            tag_group_list: $groups->map(fn (TagGroup $group) => ListTagGroupItemData::fromModel($group))->all(),
            scope_options: EnumOptionData::fromCases(TagScope::cases()),
        );
    }

    /**
     * 返回标签管理页面。
     */
    public function asController(Request $request): Response
    {
        $currentSystem = SystemUserContextData::fromRequest($request)->systemContext();
        $props = $this->handle($currentSystem);

        return Inertia::render('tags/Index', $props->toArray());
    }
}
