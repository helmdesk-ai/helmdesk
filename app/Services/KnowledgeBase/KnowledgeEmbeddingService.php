<?php

namespace App\Services\KnowledgeBase;

use App\Exceptions\BusinessException;
use App\Models\AiModel;
use App\Models\AiProvider;

/**
 * 嵌入模型调用统一入口。
 *
 * 负责把"调一次模型 → 拿一组向量"的细节（分批、维度一致性校验、凭据解析）
 * 收敛到一处，让 Vector / Raptor / QA 三个索引器都共用一份实现，避免各自维护
 * embedInBatches + credentialsFor 的副本。
 */
class KnowledgeEmbeddingService
{
    /**
     * 一次性投递给 Go embed 接口的最大 chunk 数；过大拉长单次超时，过小增加桥接调用次数。
     */
    public const DEFAULT_BATCH_SIZE = 32;

    public function __construct(
        private readonly GoKnowledgeBridge $bridge,
    ) {}

    /**
     * 按 batchSize 分批嵌入一组文本，返回 [dimension, vectors]。
     * 任意一批返回维度与首批不一致时抛 BusinessException。
     *
     * @param  list<string>  $contents
     * @return array{0: int, 1: list<list<float>>}
     */
    public function embedTexts(AiModel $model, array $contents, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        if ($contents === []) {
            return [0, []];
        }

        $provider = $model->provider;
        if ($provider === null) {
            throw new BusinessException(__('knowledge_base.messages.invalid_embedding_model'));
        }

        $credentials = $this->credentialsFor($provider);

        $dimension = 0;
        $vectors = [];
        foreach (array_chunk($contents, max(1, $batchSize)) as $batch) {
            $result = $this->bridge->embedTexts($provider, $model, $credentials, $batch);
            $batchDimension = (int) $result['dimension'];
            if ($dimension === 0) {
                $dimension = $batchDimension;
            } elseif ($batchDimension !== $dimension) {
                throw new BusinessException(__('knowledge_base.documents.errors.embedding_failed'));
            }
            foreach ($result['embeddings'] as $vector) {
                $vectors[] = $vector;
            }
        }

        if ($dimension <= 0 || count($vectors) !== count($contents)) {
            throw new BusinessException(__('knowledge_base.documents.errors.embedding_failed'));
        }

        return [$dimension, $vectors];
    }

    /**
     * 解密供应商凭据为 PHP 数组；既兼容已解密的数组也兼容仍是字符串的旧值。
     *
     * @return array<string, mixed>
     */
    public function credentialsFor(AiProvider $provider): array
    {
        $raw = $provider->credentials;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
