<?php

namespace App\Services\KnowledgeBase\Search;

use App\Models\Workspace;
use App\Services\KnowledgeBase\GoKnowledgeBridge;
use App\Services\KnowledgeBase\KnowledgeEmbeddingService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 重排序器。
 *
 * 工作区配置了 rerank 模型时，调 GoKnowledgeBridge::rerank 拿到分数，写回
 * hit.metadata['rerank_score'] 并按分数降序排列，返回 applied=true 的结果。
 *
 * 以下场景退回为按 fused 顺序的截断 (applied=false)，errorCode 给出稳定标识：
 *  - 工作区未配置 rerank 模型 → model_missing
 *  - Go 桥 / 远端任何调用失败 → remote_unavailable（详细异常进服务端日志）
 *  - 远端返回 results 为空 / 无可用 index → empty_response
 *
 * query 是 rerank 用的检索短语；Agent 传多条 query 时，由调用方挑一条最具代表性的传入。
 */
class KnowledgeReranker
{
    public function __construct(
        private readonly GoKnowledgeBridge $bridge,
        private readonly KnowledgeEmbeddingService $embedder,
    ) {}

    /**
     * 给一组 hits 做重排。
     *
     * @param  list<KnowledgeSearchHit>  $hits
     */
    public function rerank(Workspace $workspace, string $query, array $hits, int $topK): KnowledgeRerankResult
    {
        if ($hits === [] || trim($query) === '' || $topK <= 0) {
            return new KnowledgeRerankResult(
                hits: [],
                applied: false,
            );
        }

        $workspace->loadMissing('knowledgeRerankModel.provider');
        $model = $workspace->knowledgeRerankModel;
        if ($model === null || $model->provider === null) {
            return new KnowledgeRerankResult(
                hits: array_slice($hits, 0, $topK),
                applied: false,
                errorCode: 'model_missing',
            );
        }

        $documents = array_map(static fn (KnowledgeSearchHit $hit): string => $hit->content, $hits);

        try {
            $credentials = $this->embedder->credentialsFor($model->provider);
            $response = $this->bridge->rerank($model->provider, $model, $credentials, $query, $documents, $topK);
        } catch (Throwable $exception) {
            Log::info('Knowledge rerank unavailable, falling back to fused ordering.', [
                'workspace_id' => $workspace->id,
                'message' => $exception->getMessage(),
            ]);

            return new KnowledgeRerankResult(
                hits: array_slice($hits, 0, $topK),
                applied: false,
                errorCode: 'remote_unavailable',
            );
        }

        $results = $response['results'] ?? [];
        if ($results === []) {
            return new KnowledgeRerankResult(
                hits: array_slice($hits, 0, $topK),
                applied: false,
                errorCode: 'empty_response',
            );
        }

        // results 由 Go 桥透传外部模型的 (index, score) 列表；index 对齐 documents 数组。
        // 外部模型偶尔会返回非法 index 或字段缺失，单条静默丢弃；全部丢弃则按 empty_response 降级。
        $scored = [];
        foreach ($results as $row) {
            $index = (int) ($row['index'] ?? -1);
            if ($index < 0 || $index >= count($hits)) {
                continue;
            }
            $scored[$index] = (float) ($row['score'] ?? 0.0);
        }
        if ($scored === []) {
            return new KnowledgeRerankResult(
                hits: array_slice($hits, 0, $topK),
                applied: false,
                errorCode: 'empty_response',
            );
        }

        arsort($scored);
        $top = array_slice($scored, 0, $topK, preserve_keys: true);

        $ranked = [];
        $position = 0;
        foreach ($top as $index => $score) {
            $position++;
            $hit = $hits[$index];
            $metadata = $hit->metadata;
            $metadata['rerank_score'] = $score;
            $ranked[] = $hit->with([
                'score' => $score,
                'rank' => $position,
                'metadata' => $metadata,
            ]);
        }

        return new KnowledgeRerankResult(
            hits: $ranked,
            applied: true,
        );
    }
}
