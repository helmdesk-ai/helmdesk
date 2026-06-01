<?php

namespace App\Actions\Native\Reception;

use App\Actions\Reception\StartOrResumeReceptionSessionAction;
use App\Data\Reception\ReceptionStateData;
use App\Enums\ConversationEntryMode;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Native bridge 入口：启动或恢复访客接待会话。
 */
class StartOrResumeReceptionSessionBridgeAction
{
    use AsAction;

    /**
     * 注入真正负责接待会话启动和恢复的业务 Action。
     */
    public function __construct(
        private readonly StartOrResumeReceptionSessionAction $startOrResumeReceptionSessionAction,
    ) {}

    /**
     * 将 Go 传入的小类型参数转换后启动或恢复访客接待会话。
     *
     * @param  array{locale?: string|null, timezone?: string|null, country?: string|null, city?: string|null}|null  $visitorEnvironment
     * @param  array<string, mixed>|null  $queryParams
     * @param  array<string, mixed>|null  $visitorClient  Go 边缘透传的 Web 访客信号：current_url/entry_url/landing_url/referrer/user_agent/ip_address/browser_language
     */
    public function handle(
        string $channelCode,
        ?string $sessionToken,
        ?string $entryMode = null,
        ?array $visitorEnvironment = null,
        ?string $userToken = null,
        ?array $queryParams = null,
        ?array $visitorClient = null,
    ): ReceptionStateData {
        return $this->startOrResumeReceptionSessionAction->handle(
            channelCode: $channelCode,
            sessionToken: $sessionToken,
            entryMode: $this->entryMode($entryMode),
            visitorEnvironment: $visitorEnvironment,
            userToken: $userToken,
            queryParams: $this->normalizeQueryParams($queryParams),
            visitorClient: $this->normalizeVisitorClient($visitorClient),
        );
    }

    /**
     * 清洗 Go bridge 透传的访客信号：丢弃非字符串键/值，保留原始内容交给采集 Action 做长度截断。
     *
     * @param  array<string, mixed>|null  $visitorClient
     * @return array<string, string>
     */
    private function normalizeVisitorClient(?array $visitorClient): array
    {
        if ($visitorClient === null) {
            return [];
        }

        $normalized = [];
        foreach ($visitorClient as $key => $value) {
            if (is_string($key) && $key !== '' && is_string($value)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $queryParams
     * @return array<string, string>
     */
    private function normalizeQueryParams(?array $queryParams): array
    {
        if ($queryParams === null) {
            return [];
        }

        $normalized = [];
        foreach ($queryParams as $key => $value) {
            if (is_string($key) && $key !== '' && is_string($value)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * 将 Native bridge 的字符串入口模式转换为业务层枚举。
     */
    private function entryMode(?string $entryMode): ConversationEntryMode
    {
        $entryMode = trim((string) $entryMode);
        if ($entryMode === '') {
            return ConversationEntryMode::Standalone;
        }

        return ConversationEntryMode::tryFrom($entryMode)
            ?? throw ValidationException::withMessages(['entry_mode' => __('validation.in', ['attribute' => 'entry_mode'])]);
    }
}
