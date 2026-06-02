<?php

namespace App\Actions\Channel\Telegram;

use App\Actions\Reception\Plan\ListReceptionPlansForChannelSelectionAction;
use App\Data\Channel\Telegram\ShowTelegramChannelDetailPagePropsData;
use App\Data\Channel\Telegram\TelegramChannelData;
use App\Data\Channel\Telegram\TelegramChannelFormOptionsData;
use App\Data\EnumOptionData;
use App\Data\SystemUserContextData;
use App\Enums\ChannelType;
use App\Enums\ReceptionLanguage;
use App\Models\Channel;
use App\Models\SystemContext;
use App\Services\Reception\ChannelReceptionPlanVersionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示 Telegram 渠道详情页及基本信息表单数据。
 */
class ShowTelegramChannelDetailPageAction
{
    use AsAction;

    /**
     * 注入接待方案选项与渠道部署版本状态解析器。
     */
    public function __construct(
        private ListReceptionPlansForChannelSelectionAction $listReceptionPlans,
        private ChannelReceptionPlanVersionResolver $planVersionResolver,
    ) {}

    /**
     * 组装 Telegram 渠道详情页和表单选项。
     */
    public function handle(SystemContext $systemContext, string $channelId): ShowTelegramChannelDetailPagePropsData
    {
        $channel = Channel::query()
            ->where('type', ChannelType::Telegram)
            ->with(['receptionPlan'])
            ->findOrFail($channelId);

        return new ShowTelegramChannelDetailPagePropsData(
            telegram_channel: TelegramChannelData::fromModel(
                $channel,
                $this->planVersionResolver->resolveChannelStatus($systemContext, $channel),
            ),
            form_options: new TelegramChannelFormOptionsData(
                reception_plan_options: $this->listReceptionPlans->handle($systemContext),
                reception_language_options: EnumOptionData::fromCases(ReceptionLanguage::cases()),
            ),
        );
    }

    /**
     * 返回 Telegram 渠道详情页面。
     */
    public function asController(Request $request, string $channel): Response
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('admin.manageAi', [$systemContext]);

        return Inertia::render('channel/telegram/Show', $this->handle($systemContext, $channel)->toArray());
    }
}
