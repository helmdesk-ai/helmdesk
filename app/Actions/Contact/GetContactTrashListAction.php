<?php

namespace App\Actions\Contact;

use App\Data\Contact\ShowContactTrashPagePropsData;
use App\Data\Contact\TrashContactItemData;
use App\Data\EnumOptionData;
use App\Data\SimplePaginationData;
use App\Data\WorkspaceUserContextData;
use App\Enums\ContactListType;
use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 查询已删除联系人列表。
 */
class GetContactTrashListAction
{
    use AsAction;

    /**
     * 查询工作区联系人回收站列表页 props。
     */
    public function handle(
        Workspace $workspace,
        int $page = 1,
        int $perPage = 15,
    ): ShowContactTrashPagePropsData {
        $perPage = max(1, min($perPage, 50));
        $page = max(1, $page);

        $paginator = Contact::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('deleted_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $contacts = $paginator->getCollection()
            ->map(fn (Contact $contact) => TrashContactItemData::fromModel($contact))
            ->all();

        return new ShowContactTrashPagePropsData(
            contact_trash_list: $contacts,
            contact_trash_list_pagination: SimplePaginationData::fromPaginator($paginator),
            contact_list_type_options: EnumOptionData::fromCases(ContactListType::cases()),
        );
    }

    /**
     * 返回工作区联系人回收站页面。
     */
    public function asController(Request $request, string $slug): Response
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $workspace = $ctx->workspace();
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 15);

        return Inertia::render(
            'contacts/Trash',
            $this->handle($workspace, $page, $perPage)->toArray(),
        );
    }
}
