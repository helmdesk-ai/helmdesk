<?php

namespace App\Actions\Channel\Web;

use App\Actions\Attachment\AttachUploadedAttachmentsAction;
use App\Actions\Reception\Plan\ResolveChannelReceptionPlanAction;
use App\Data\Channel\Web\FormUpdateWebChannelBasicData;
use App\Data\SystemUserContextData;
use App\Enums\AttachmentPurpose;
use App\Enums\UserPermission;
use App\Exceptions\BusinessException;
use App\Models\Channel;
use App\Models\SystemContext;
use App\Services\Channel\WebChannelResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新网站渠道的基础信息和入口配置。
 */
class UpdateWebChannelBasicAction
{
    use AsAction;

    /**
     * 注入渠道接待方案解析器和渠道解析服务。
     */
    public function __construct(
        private readonly ResolveChannelReceptionPlanAction $resolveChannelReceptionPlan,
        private readonly WebChannelResolutionService $resolution,
    ) {}

    /**
     * 保存网站渠道基础信息和接待方案绑定。
     */
    public function handle(SystemContext $systemContext, Channel $channel, FormUpdateWebChannelBasicData $data): void
    {
        $submittedPlanId = $data->receptionPlanId();
        $requireUsable = $submittedPlanId !== $channel->reception_plan_id;
        $planId = $this->resolveChannelReceptionPlan->handle(
            $systemContext,
            $submittedPlanId,
            requireUsable: $requireUsable,
        );
        $settings = $channel->settings;
        if ($data->default_visitor_locale !== null) {
            $settings = $settings->mergeWith([
                'default_visitor_locale' => $data->default_visitor_locale->value,
            ]);
        }

        DB::transaction(fn () => $channel->update([
            'name' => $data->name,
            'description' => filled($data->description) ? $data->description : null,
            'reception_plan_id' => $planId,
            'settings' => $settings,
        ]));
    }

    /**
     * 接收渠道基础信息表单并返回详情页。
     */
    public function asController(Request $request, string $channel): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::ChannelsEdit);

        $channelModel = $this->resolution->findSystemChannel($systemContext, $channel);

        $this->handle($systemContext, $channelModel, FormUpdateWebChannelBasicData::from($request));

        return redirect()->back(302, [], route('admin.manage.channels.web.show', [
            'channel' => $channelModel->id,
        ]));
    }

    /**
     * 将附件绑定到渠道。
     */
    public static function bindChannelAttachment(Channel $channel, ?string $attachmentId): void
    {
        if (! filled($attachmentId)) {
            return;
        }

        AttachUploadedAttachmentsAction::run($channel, $attachmentId);
    }

    /**
     * 附件只能使用未绑定资源或当前渠道已绑定资源。
     */
    public static function assertAttachmentAssignable(Channel $channel, AttachmentPurpose $purpose, ?string $attachmentId, ?string $scope = null): void
    {
        if (! filled($attachmentId)) {
            return;
        }

        try {
            app(AttachUploadedAttachmentsAction::class)->assertCanAttach(
                attachable: $channel,
                attachmentId: $attachmentId,
                allowedPurposes: [$purpose],
            );
        } catch (ValidationException) {
            throw new BusinessException(__('channel.messages.invalid_attachment'));
        }
    }
}
