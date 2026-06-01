<?php

namespace App\Services\Realtime;

use App\Services\GoBridge\Exceptions\GoBridgeException;
use App\Services\GoBridge\GoBridgeClient;
use Illuminate\Support\Facades\Log;

/**
 * 通过 Go 桥接发布 Mercure 实时消息。
 *
 * 设计上视作 best-effort 推送：业务真值始终来自 DB，订阅方在重连时会重新 fetch 当前状态；
 * 因此发布失败只记日志，不向调用方抛异常，避免实时通道暂时故障导致用户接口 500。
 */
class MercurePublisher
{
    /**
     * 注入 Go 桥接发布器。
     */
    public function __construct(
        private readonly GoBridgeClient $goBridge,
    ) {}

    /**
     * 发布一条 Mercure 实时消息。
     *
     * 任何失败都以日志告警方式处理；不要在这里抛出异常打断业务事务后续步骤。
     *
     * @param  array<string, mixed>  $data
     */
    public function publish(string $topic, string $type, array $data): void
    {
        try {
            $response = $this->goBridge->postJson('realtime/publish', [
                'topics' => [$topic],
                'type' => $type,
                'data' => $data,
            ], 5);
        } catch (GoBridgeException $exception) {
            Log::warning('Mercure publish bridge call failed.', [
                'topic_hash' => substr(hash('sha256', $topic), 0, 12),
                'type' => $type,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        if (! $response->successful) {
            Log::warning('Mercure publish bridge returned an unsuccessful response.', [
                'topic_hash' => substr(hash('sha256', $topic), 0, 12),
                'type' => $type,
                'status' => $response->status,
                'body' => $response->body,
            ]);
        }
    }
}
