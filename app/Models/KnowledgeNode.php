<?php

namespace App\Models;

use App\Enums\KnowledgeIndexingStrategy;
use App\Enums\KnowledgeNodeKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $knowledge_base_id
 * @property string|null $document_id
 * @property string|null $qa_entry_id
 * @property string|null $qa_question_id
 * @property string|null $parent_id
 * @property KnowledgeIndexingStrategy $strategy
 * @property int $level
 * @property KnowledgeNodeKind $kind
 * @property string $content
 * @property string $content_format
 * @property string|null $heading_path
 * @property int|null $byte_start
 * @property int|null $byte_end
 * @property int|null $token_count
 * @property string|null $embedding_model_id
 * @property int $embedding_dim
 * @property array|null $metadata
 * @property int|null $parents_count
 * @property int|null $childrens_count
 * @property-read KnowledgeNode|null $parent
 * @property-read Collection|KnowledgeNode[] $children
 */
class KnowledgeNode extends Model
{
    /**
     * RAG 节点模型，存活在 sqlite_rag 连接上。
     * 同一篇文档可能存在多条策略的节点（vector 叶子 + raptor 摘要等），通过 strategy/level/parent_id 区分。
     */
    use HasUlids;

    /**
     * 节点表与向量虚表都位于独立的 sqlite_rag 连接，与主库 knowledge_documents 分库存储。
     */
    protected $connection = 'sqlite_rag';

    protected $table = 'knowledge_nodes';

    protected $guarded = [];

    /**
     * 返回字段类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => KnowledgeIndexingStrategy::class,
            'kind' => KnowledgeNodeKind::class,
            'level' => 'integer',
            'byte_start' => 'integer',
            'byte_end' => 'integer',
            'token_count' => 'integer',
            'embedding_dim' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * RAPTOR 树的父节点。
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * RAPTOR 树的子节点集合。
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
