<?php

namespace App\Actions\Channel\Web;

use App\Actions\Reception\Plan\ResolveChannelReceptionPlanAction;
use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Data\Channel\Web\FormCreateWebChannelData;
use App\Data\SystemUserContextData;
use App\Enums\ChannelType;
use App\Enums\UserPermission;
use App\Models\Channel;
use App\Models\SystemContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 创建网站渠道并生成公开访问 code 与签名访客密钥。
 */
class CreateWebChannelAction
{
    use AsAction;

    /**
     * 注入渠道接待方案解析器，确保绑定到系统内存在可用最新版本的方案。
     */
    public function __construct(
        private ResolveChannelReceptionPlanAction $resolveChannelReceptionPlan,
    ) {}

    /**
     * 创建网站渠道并绑定接待方案（运行时自动跟随其最新已发布版本）。
     */
    public function handle(SystemContext $systemContext, FormCreateWebChannelData $data): Channel
    {
        $planId = $this->resolveChannelReceptionPlan->handle(
            $systemContext,
            $data->receptionPlanId(),
            requireUsable: true,
        );

        return Channel::query()->create([
            'type' => ChannelType::Web,
            'name' => $data->name,
            'description' => filled($data->description) ? $data->description : null,
            'reception_plan_id' => $planId,
            'settings' => ChannelWebSettingsData::defaults([
                'allowed_embed_hosts' => ['*'],
                'default_visitor_locale' => $data->default_visitor_locale->value,
                'user_token_secret' => Str::random(64),
            ]),
        ]);
    }

    /**
     * 接收创建网站渠道表单并跳转到渠道详情页。
     */
    public function asController(Request $request): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::ChannelsCreate);

        $channel = $this->handle($systemContext, FormCreateWebChannelData::from($request));

        return redirect()->route('admin.manage.channels.web.show', [
            'channel' => $channel->id,
        ]);
    }
}
