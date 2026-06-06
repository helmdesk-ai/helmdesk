<?php

namespace App\Services\KnowledgeBase;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * 按 embedding 维度懒创建 sqlite-vec vec0 虚表。
 *
 * 每个 embedding 模型维度对应一张独立的 vec0 虚表，命名 knowledge_node_vectors_{dim}。
 * 维度信息注册到 knowledge_vector_tables 元表，避免每次写入都查 sqlite_master。
 */
class KnowledgeVectorTableManager
{
    /**
     * 进程内已确保过的维度集合，单 worker 内做内存级缓存。
     *
     * @var array<int, true>
     */
    private array $ensuredDimensions = [];

    /**
     * 返回 sqlite_rag 上指定维度对应的 vec0 虚表名，若不存在则按需创建。
     */
    public function ensureVectorTable(int $dimension): string
    {
        if ($dimension <= 0) {
            throw new \InvalidArgumentException('vector dimension must be positive');
        }

        $tableName = $this->tableNameFor($dimension);
        if (isset($this->ensuredDimensions[$dimension])) {
            return $tableName;
        }

        $connection = $this->connection();
        $registered = $connection->table('knowledge_vector_tables')->where('dimension', $dimension)->exists();

        if (! $registered) {
            $connection->statement(sprintf(
                'CREATE VIRTUAL TABLE IF NOT EXISTS %s USING vec0(node_id TEXT PRIMARY KEY, embedding FLOAT[%d])',
                $tableName,
                $dimension,
            ));

            $connection->table('knowledge_vector_tables')->insertOrIgnore([
                'dimension' => $dimension,
                'table_name' => $tableName,
                'created_at' => now(),
            ]);
        }

        $this->ensuredDimensions[$dimension] = true;

        return $tableName;
    }

    /**
     * 写入或覆盖一个节点的向量。embedding 必须是与 dim 一致长度的 float 列表。
     *
     * @param  list<float>  $embedding
     */
    public function upsertVector(int $dimension, string $nodeId, array $embedding): void
    {
        if (count($embedding) !== $dimension) {
            throw new \InvalidArgumentException(sprintf(
                'embedding length %d does not match expected dimension %d',
                count($embedding),
                $dimension,
            ));
        }

        $tableName = $this->ensureVectorTable($dimension);
        $payload = json_encode($embedding, JSON_THROW_ON_ERROR);

        $connection = $this->connection();
        $connection->statement(sprintf('DELETE FROM %s WHERE node_id = ?', $tableName), [$nodeId]);
        $connection->statement(
            sprintf('INSERT INTO %s(node_id, embedding) VALUES (?, ?)', $tableName),
            [$nodeId, $payload],
        );
    }

    /**
     * 对指定维度做向量 KNN 检索。
     *
     * sqlite-vec 的 vec0 表只能在 `WHERE embedding MATCH ?` 这条单一谓词上做 KNN，不支持
     * 把 systemContext / kb / strategy 这类元信息谓词下推。调用方需要自己控制 k：传"本次允许
     * 参与召回的节点数 + 适当余量"，再在结果上做元信息交集（VectorRetriever 就是这么做的）。
     *
     * @param  list<float>  $embedding
     * @param  int  $k  KNN 取多少候选
     * @return list<array{node_id: string, distance: float}>
     */
    public function knnSearch(int $dimension, array $embedding, int $k): array
    {
        if ($k <= 0) {
            return [];
        }
        if (count($embedding) !== $dimension) {
            throw new \InvalidArgumentException(sprintf(
                'embedding length %d does not match expected dimension %d',
                count($embedding),
                $dimension,
            ));
        }

        $tableName = $this->ensureVectorTable($dimension);
        $payload = json_encode($embedding, JSON_THROW_ON_ERROR);

        $rows = $this->connection()->select(
            sprintf(
                'SELECT node_id, distance FROM %s WHERE embedding MATCH ? AND k = ? ORDER BY distance',
                $tableName,
            ),
            [$payload, $k],
        );

        $results = [];
        foreach ($rows as $row) {
            $results[] = ['node_id' => (string) $row->node_id, 'distance' => (float) $row->distance];
        }

        return $results;
    }

    /**
     * 按节点 ID 批量删除对应维度的向量记录。
     *
     * @param  list<string>  $nodeIds
     */
    public function deleteVectors(int $dimension, array $nodeIds): void
    {
        if ($nodeIds === []) {
            return;
        }

        $tableName = $this->ensureVectorTable($dimension);
        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
        $this->connection()->statement(
            sprintf('DELETE FROM %s WHERE node_id IN (%s)', $tableName, $placeholders),
            $nodeIds,
        );
    }

    /**
     * 把所有 vec0 虚表与维度注册表一次性 drop，等下一轮索引按需重建。
     *
     * 用于系统维度变更等"全量重建"场景，让向量存储与新维度一同从零开始。
     * 当前部署按维度共享 vec0 虚表，整体删除比按 node_id 逐条清理更直接。
     */
    public function resetAllTables(): void
    {
        $connection = $this->connection();
        $registered = $connection->table('knowledge_vector_tables')->pluck('table_name');
        foreach ($registered as $tableName) {
            $connection->statement('DROP TABLE IF EXISTS '.$tableName);
        }
        $connection->table('knowledge_vector_tables')->delete();
        $this->ensuredDimensions = [];
    }

    /**
     * 根据 dimension 计算虚表名。维度不同也会对应到不同表，写入侧需要保证传入合法 dim。
     */
    private function tableNameFor(int $dimension): string
    {
        return 'knowledge_node_vectors_'.$dimension;
    }

    /**
     * 拿到 sqlite_rag 连接（vec 扩展已通过 AppServiceProvider 的事件监听器自动加载）。
     */
    private function connection(): ConnectionInterface
    {
        return DB::connection('sqlite_rag');
    }
}
