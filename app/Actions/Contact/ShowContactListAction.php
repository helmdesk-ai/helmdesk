<?php

namespace App\Actions\Contact;

use App\Contracts\ContactTagFilterStrategy;
use App\Data\Contact\ContactTagFilterData;
use App\Data\Contact\ListContactItemData;
use App\Data\Contact\ShowContactListPagePropsData;
use App\Data\CustomAttribute\FilterAttributeDefinitionData;
use App\Data\EnumOptionData;
use App\Data\SimplePaginationData;
use App\Data\SystemUserContextData;
use App\Data\Tag\TagOptionData;
use App\Enums\AttributeType;
use App\Enums\ContactListType;
use App\Enums\TagMatchMode;
use App\Models\AttributeDefinition;
use App\Models\Contact;
use App\Models\SystemContext;
use App\Models\Tag;
use App\Services\CustomAttribute\ScopedAttributeFilterHelper;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 查询联系人列表，并应用标签和自定义属性筛选。
 */
class ShowContactListAction
{
    use AsAction;

    public function __construct(
        private readonly ContactTagFilterStrategy $tagFilter,
        private readonly ScopedAttributeFilterHelper $attributeFilterHelper,
    ) {}

    /**
     * @param  array<string, mixed>  $attributeFilters
     */
    public function handle(
        SystemContext $systemContext,
        ContactListType $type = ContactListType::All,
        ?string $search = null,
        int $page = 1,
        int $perPage = 15,
        array $attributeFilters = [],
        ?ContactTagFilterData $tagFilter = null,
        bool $importantOnly = false,
    ): ShowContactListPagePropsData {
        $perPage = max(1, min($perPage, 50));
        $page = max(1, $page);
        $tagFilter ??= ContactTagFilterData::unfiltered();

        $attributeFilterDefinitions = $systemContext->attributeDefinitions()
            ->active()
            ->where('is_filterable', true)
            ->whereIn('type', array_map(fn (AttributeType $type) => $type->value, AttributeType::filterableCases()))
            ->ordered()
            ->get();

        $definitionsByKey = $attributeFilterDefinitions->keyBy('key');
        $normalizedAttributeFilters = $this->attributeFilterHelper->normalizeFilters($definitionsByKey, $attributeFilters);

        $query = $systemContext->contacts()->with(['identities', 'tags']);

        $contactType = $type->contactType();
        if ($contactType !== null) {
            $query->where('type', $contactType);
        }

        if ($importantOnly) {
            $query->where('is_important', true);
        }

        $this->attributeFilterHelper->applyFilters(
            $query,
            $systemContext,
            $definitionsByKey,
            $normalizedAttributeFilters,
            'contact_attribute_values',
            'contact_id',
        );

        if (filled($search)) {
            $contactIds = Contact::search($search)
                ->keys();

            $query->whereIn('id', $contactIds);
        }

        $availableTagModels = Tag::query()
            ->orderBy('name')
            ->get();

        $availableTagIds = $availableTagModels->pluck('id')->all();
        $tagFilter = $tagFilter->restrictedTo($availableTagIds);

        if (! $tagFilter->isEmpty()) {
            $this->tagFilter->apply($query, $tagFilter);
        }

        $paginator = $query
            ->orderByDesc('is_important')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $contacts = $paginator->getCollection()
            ->map(fn (Contact $c) => ListContactItemData::fromModel($c))
            ->all();

        $availableTags = $availableTagModels
            ->map(fn (Tag $tag) => TagOptionData::fromModel($tag))
            ->all();

        return new ShowContactListPagePropsData(
            contact_list: $contacts,
            contact_list_pagination: SimplePaginationData::fromPaginator($paginator),
            contact_list_type_options: EnumOptionData::fromCases(ContactListType::cases()),
            tag_match_mode_options: EnumOptionData::fromCases(TagMatchMode::cases()),
            current_type: $type,
            search: $search,
            important_only: $importantOnly,
            attribute_filter_definitions: $attributeFilterDefinitions
                ->map(fn (AttributeDefinition $definition) => FilterAttributeDefinitionData::fromModel($definition))
                ->all(),
            attribute_filters: $normalizedAttributeFilters,
            available_tags: $availableTags,
            tag_filter: $tagFilter,
        );
    }

    public function asController(Request $request, string $type): Response
    {
        $ctx = SystemUserContextData::fromRequest($request);
        $systemContext = $ctx->systemContext();
        $listType = ContactListType::tryFrom($type) ?? throw new NotFoundHttpException;
        $search = $request->query('search');
        $importantOnly = $request->boolean('important');
        $page = (int) $request->query('page', 1);
        $attributeFilters = $request->query('attribute_filters', []);
        $tagFilter = ContactTagFilterData::fromRequest($request);

        $props = $this->handle(
            systemContext: $systemContext,
            type: $listType,
            search: $search,
            importantOnly: $importantOnly,
            page: $page,
            attributeFilters: is_array($attributeFilters) ? $attributeFilters : [],
            tagFilter: $tagFilter,
        );

        return Inertia::render('contacts/Index', $props->toArray());
    }
}
