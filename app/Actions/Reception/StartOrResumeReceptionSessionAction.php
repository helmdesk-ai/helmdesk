<?php

namespace App\Actions\Reception;

use App\Data\Reception\ReceptionStateData;
use App\Enums\ConversationEntryMode;
use App\Services\Reception\ReceptionStateBuilder;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 启动或恢复访客接待会话，并返回前端状态。
 */
class StartOrResumeReceptionSessionAction
{
    use AsAction;

    /**
     * 注入接待上下文解析 Action。
     */
    public function __construct(
        private readonly ResolveReceptionContextAction $resolveReceptionContextAction,
    ) {}

    /**
     * 解析访客上下文并返回当前接待会话状态。
     */
    /**
     * @param  array<string, string>|null  $queryParams
     * @param  array<string, mixed>|null  $visitorClient
     */
    public function handle(
        string $channelCode,
        ?string $sessionToken,
        ?ConversationEntryMode $entryMode = null,
        ?array $visitorEnvironment = null,
        ?string $userToken = null,
        ?array $queryParams = null,
        ?array $visitorClient = null,
    ): ReceptionStateData {
        $context = $this->resolveReceptionContextAction->handle(
            $channelCode,
            $sessionToken,
            $entryMode,
            $visitorEnvironment,
            $userToken,
            $queryParams,
            $visitorClient,
        );

        return ReceptionStateBuilder::build($context['channel'], $context['conversation'], $context['session_token']);
    }
}
