<?php

namespace App\Actions\Contact;

use App\Data\Contact\ContactActivityLogData;
use App\Data\Contact\ContactDetailData;
use App\Data\CustomAttribute\ContactAttributeFieldData;
use App\Data\WorkspaceUserContextData;
use App\Models\Contact;
use App\Models\ContactActivityLog;
use App\Models\ContactAttributeValue;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示联系人详情及标签、自定义属性等侧栏数据。
 */
class ShowContactDetailAction
{
    use AsAction;

    public function handle(Workspace $workspace, string $contactId, bool $includeTrashed = false): ContactDetailData
    {
        $contactQuery = Contact::query()
            ->when($includeTrashed, fn ($query) => $query->withTrashed())
            ->with([
                'identities' => fn ($query) => $includeTrashed ? $query->withTrashed() : $query,
            ]);

        $contact = $contactQuery->findOrFail($contactId);

        $activityLogs = ContactActivityLog::query()
            ->where('contact_id', $contactId)
            ->with([
                'actor',
                'relatedContact' => fn ($query) => $query->withTrashed(),
            ])
            ->latest('created_at')
            ->get()
            ->map(fn (ContactActivityLog $activityLog) => ContactActivityLogData::fromModel($activityLog));

        $customAttributes = $this->buildCustomAttributeFields($workspace, $contact);

        return ContactDetailData::fromModel($contact, $activityLogs, $customAttributes);
    }

    /**
     * @return ContactAttributeFieldData[]
     */
    private function buildCustomAttributeFields(Workspace $workspace, Contact $contact): array
    {
        $activeDefinitions = $workspace->attributeDefinitions()
            ->active()
            ->ordered()
            ->get();

        $contactValues = ContactAttributeValue::query()
            ->where('contact_id', $contact->id)
            ->with('definition')
            ->get()
            ->keyBy('definition_id');

        $deletedWithValues = $contactValues
            ->filter(fn (ContactAttributeValue $val) => $val->definition?->trashed())
            ->map(fn (ContactAttributeValue $val) => $val->definition)
            ->filter();

        $allDefinitions = $activeDefinitions->merge($deletedWithValues);

        $fields = [];

        foreach ($allDefinitions as $definition) {
            $value = $contactValues->get($definition->id);

            $fields[] = new ContactAttributeFieldData(
                definition_id: $definition->id,
                key: $definition->key,
                name: $definition->name,
                description: $definition->description,
                type: $definition->type->value,
                type_label: $definition->type->label(),
                config: $definition->config,
                value: $value?->value(),
                source: $value?->source?->value,
                source_label: $value?->source?->label(),
                deleted_at: $definition->deleted_at?->toIso8601String(),
                is_editable: ! $definition->trashed(),
            );
        }

        return $fields;
    }

    public function asController(Request $request, string $id): JsonResponse
    {
        $ctx = WorkspaceUserContextData::fromRequest($request);
        $workspace = $ctx->workspace();
        $includeTrashed = $request->boolean('include_trashed');

        return response()->json($this->handle($workspace, $id, $includeTrashed)->toArray());
    }
}
