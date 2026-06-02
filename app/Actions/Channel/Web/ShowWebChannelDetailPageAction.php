<?php

namespace App\Actions\Channel\Web;

use App\Actions\Reception\Plan\ListReceptionPlansForChannelSelectionAction;
use App\Data\Channel\Web\QueryParamOptionData;
use App\Data\Channel\Web\ShowWebChannelDetailPagePropsData;
use App\Data\Channel\Web\WebChannelData;
use App\Data\Channel\Web\WebChannelFormOptionsData;
use App\Data\Channel\Web\WritableAttributeDefinitionOptionData;
use App\Data\EnumOptionData;
use App\Data\SystemUserContextData;
use App\Enums\AttributeType;
use App\Enums\Channel\Web\WebChannelParamTarget;
use App\Enums\Channel\Web\WebChannelParamTrust;
use App\Enums\Channel\Web\WebChannelParamWriteMode;
use App\Enums\Channel\Web\WebChannelVisitorIdentityMode;
use App\Enums\Channel\Web\WebChannelWidgetEntryMode;
use App\Enums\Channel\Web\WebChannelWidgetEntryPosition;
use App\Enums\Channel\Web\WebChannelWidgetEntryStyle;
use App\Enums\Channel\Web\WebChannelWidgetIconSize;
use App\Enums\ChannelType;
use App\Enums\ReceptionLanguage;
use App\Models\AttributeDefinition;
use App\Models\Channel;
use App\Models\SystemContext;
use App\Services\Reception\ChannelReceptionPlanVersionResolver;
use App\Support\Channel\WebChannelThemePalette;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示网站渠道详情页及各配置表单数据。
 */
class ShowWebChannelDetailPageAction
{
    use AsAction;

    /**
     * 注入接待方案选项与渠道部署状态解析器。
     */
    public function __construct(
        private ListReceptionPlansForChannelSelectionAction $listReceptionPlans,
        private ChannelReceptionPlanVersionResolver $planVersionResolver,
    ) {}

    /**
     * 组装网站渠道详情页和配置表单选项。
     */
    public function handle(SystemContext $systemContext, string $channelId): ShowWebChannelDetailPagePropsData
    {
        $channel = Channel::query()
            ->where('type', ChannelType::Web)
            ->with(['receptionPlan'])
            ->findOrFail($channelId);

        return new ShowWebChannelDetailPagePropsData(
            web_channel: WebChannelData::fromModel(
                $channel,
                $this->planVersionResolver->resolveChannelStatus($systemContext, $channel),
            ),
            form_options: new WebChannelFormOptionsData(
                reception_plan_options: $this->listReceptionPlans->handle($systemContext),
                visitor_identity_mode_options: EnumOptionData::fromCases(WebChannelVisitorIdentityMode::cases()),
                query_param_options: QueryParamOptionData::options(),
                theme_color_options: WebChannelThemePalette::presets(),
                widget_entry_mode_options: EnumOptionData::fromCases(WebChannelWidgetEntryMode::cases()),
                widget_entry_position_options: EnumOptionData::fromCases(WebChannelWidgetEntryPosition::cases()),
                widget_entry_style_options: EnumOptionData::fromCases(WebChannelWidgetEntryStyle::cases()),
                widget_icon_size_options: EnumOptionData::fromCases(WebChannelWidgetIconSize::cases()),
                query_param_target_options: EnumOptionData::fromCases(WebChannelParamTarget::cases()),
                query_param_trust_options: EnumOptionData::fromCases(WebChannelParamTrust::cases()),
                query_param_write_mode_options: EnumOptionData::fromCases(WebChannelParamWriteMode::cases()),
                writable_attribute_definition_options: AttributeDefinition::query()
                    ->whereNull('deleted_at')
                    ->where('is_api_writable', true)
                    ->whereIn('type', [
                        AttributeType::Text,
                        AttributeType::Textarea,
                        AttributeType::Number,
                        AttributeType::Date,
                        AttributeType::SingleSelect,
                    ])
                    ->orderBy('display_order')
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (AttributeDefinition $definition): WritableAttributeDefinitionOptionData => WritableAttributeDefinitionOptionData::fromModel($definition))
                    ->all(),
                reception_language_options: EnumOptionData::fromCases(ReceptionLanguage::cases()),
            ),
        );
    }

    /**
     * 返回网站渠道详情页面。
     */
    public function asController(Request $request, string $channel): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        return Inertia::render('channel/web/Show', $this->handle($systemContext, $channel)->toArray());
    }
}
