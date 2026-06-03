<?php

namespace App\Actions\Channel\Web;

use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Data\Channel\Web\FormUpdateWebChannelEmbedData;
use App\Data\Channel\Web\WebChannelQueryParamMappingData;
use App\Data\SystemUserContextData;
use App\Enums\Channel\Web\WebChannelParamTarget;
use App\Enums\UserPermission;
use App\Models\Channel;
use App\Services\Channel\WebChannelResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新网站渠道明文业务参数自动写入规则。
 */
class UpdateWebChannelEmbedAction
{
    use AsAction;

    /**
     * 注入渠道解析服务。
     */
    public function __construct(
        private readonly WebChannelResolutionService $resolution,
    ) {}

    /**
     * 保存业务参数映射；嵌入域名白名单由接入方式表单单独维护，这里不触碰。
     */
    public function handle(Channel $channel, FormUpdateWebChannelEmbedData $data): void
    {
        $mappings = $this->normalizeMappings($data->query_param_mappings?->toCollection()->all() ?? []);

        /** @var ChannelWebSettingsData $currentSettings */
        $currentSettings = $channel->settings;

        $settings = $currentSettings->mergeWith([
            'query_param_mappings' => array_map(
                static fn (WebChannelQueryParamMappingData $mapping): array => $mapping->toArray(),
                $mappings,
            ),
        ]);

        DB::transaction(fn () => $channel->update([
            'settings' => $settings,
        ]));
    }

    /**
     * 接收业务参数映射表单并返回渠道详情页。
     */
    public function asController(Request $request, string $channel): RedirectResponse
    {
        $systemContext = SystemUserContextData::fromRequest($request)->systemContext();
        Gate::authorize('user.permission', UserPermission::ChannelsEdit);

        $channelModel = $this->resolution->findSystemChannel($systemContext, $channel);

        $this->handle($channelModel, FormUpdateWebChannelEmbedData::from($request));

        return redirect()->back(302, [], route('admin.manage.channels.web.show', [
            'channel' => $channelModel->id,
        ]));
    }

    /**
     * 规范化映射列表；同一 param_name + target 组合保留最后一条。
     * 属性 / 标签类必须填写 target_key。
     *
     * @param  array<int, mixed>  $rawMappings
     * @return list<WebChannelQueryParamMappingData>
     */
    private function normalizeMappings(array $rawMappings): array
    {
        $byKey = [];

        foreach ($rawMappings as $index => $raw) {
            if (! $raw instanceof WebChannelQueryParamMappingData) {
                continue;
            }

            $paramName = trim($raw->param_name);
            if ($paramName === '') {
                continue;
            }

            if ($raw->target->requiresTargetKey() && trim((string) ($raw->target_key ?? '')) === '') {
                throw ValidationException::withMessages([
                    "query_param_mappings.{$index}.target_key" => __('validation.required', ['attribute' => 'target_key']),
                ]);
            }

            if ($raw->target === WebChannelParamTarget::Tag) {
                $template = trim((string) $raw->target_key);
                if (mb_strlen($template) > 120) {
                    throw ValidationException::withMessages([
                        "query_param_mappings.{$index}.target_key" => __('validation.max.string', ['attribute' => 'target_key', 'max' => 120]),
                    ]);
                }
            }

            $key = $paramName.'|'.$raw->target->value.'|'.($raw->target_key ?? '');
            $byKey[$key] = new WebChannelQueryParamMappingData(
                param_name: $paramName,
                target: $raw->target,
                target_key: $raw->target_key !== null ? trim($raw->target_key) : null,
                trust: $raw->trust,
                write_mode: $raw->write_mode,
            );
        }

        return array_values($byKey);
    }
}
