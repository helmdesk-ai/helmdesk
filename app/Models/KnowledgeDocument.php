<?php

namespace App\Models;

use App\Enums\AttachmentPurpose;
use App\Enums\KnowledgeDocumentIndexingStatus;
use App\Enums\KnowledgeDocumentParseStatus;
use App\Enums\KnowledgeDocumentSourceType;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeIndexingStrategy;
use Database\Factories\KnowledgeDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $workspace_id
 * @property string $knowledge_base_id
 * @property string $group_id
 * @property string|null $uploaded_by_user_id
 * @property string $original_filename
 * @property string $mime_type
 * @property int $byte_size
 * @property string|null $extension
 * @property string|null $checksum_sha256
 * @property KnowledgeDocumentSourceType $source_type
 * @property KnowledgeDocumentStatus $status
 * @property string|null $error_message
 * @property string|null $content
 * @property KnowledgeDocumentParseStatus $parse_status
 * @property string|null $parse_error
 * @property Carbon|null $parsed_at
 * @property string|null $parsed_content_format
 * @property string|null $parsed_content
 * @property array|null $parse_metadata
 * @property KnowledgeDocumentIndexingStatus $vector_status
 * @property string|null $vector_error
 * @property Carbon|null $vector_indexed_at
 * @property KnowledgeDocumentIndexingStatus $raptor_status
 * @property string|null $raptor_error
 * @property Carbon|null $raptor_indexed_at
 * @property mixed $use_factory
 * @property int|null $workspaces_count
 * @property int|null $knowledge_bases_count
 * @property int|null $groups_count
 * @property int|null $uploaded_bies_count
 * @property int|null $original_files_count
 * @property-read Workspace $workspace
 * @property-read KnowledgeBase $knowledgeBase
 * @property-read KnowledgeGroup $group
 * @property-read User|null $uploadedBy
 * @property-read Attachment|null $originalFile
 *
 * @method static \Database\Factories\KnowledgeDocumentFactory<self> factory($count = null, $state = [])
 */
class KnowledgeDocument extends Model
{
    /**
     * 知识库文档模型，保存上传文件、解析结果和索引阶段状态。
     */

    /** @use HasFactory<KnowledgeDocumentFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * 返回字段类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'source_type' => KnowledgeDocumentSourceType::class,
            'status' => KnowledgeDocumentStatus::class,
            'parse_status' => KnowledgeDocumentParseStatus::class,
            'parsed_at' => 'datetime',
            'parse_metadata' => 'array',
            'vector_status' => KnowledgeDocumentIndexingStatus::class,
            'vector_indexed_at' => 'datetime',
            'raptor_status' => KnowledgeDocumentIndexingStatus::class,
            'raptor_indexed_at' => 'datetime',
        ];
    }

    /**
     * 返回指定策略当前的索引状态。Text 始终启用且与解析共生，这里复用 parse_status 的就绪态做近似展示。
     */
    public function indexingStatusFor(KnowledgeIndexingStrategy $strategy): KnowledgeDocumentIndexingStatus
    {
        return match ($strategy) {
            KnowledgeIndexingStrategy::Vector => $this->vector_status,
            KnowledgeIndexingStrategy::Raptor => $this->raptor_status,
            KnowledgeIndexingStrategy::Text => $this->parse_status === KnowledgeDocumentParseStatus::Succeeded
                ? KnowledgeDocumentIndexingStatus::Succeeded
                : KnowledgeDocumentIndexingStatus::Pending,
        };
    }

    /**
     * 更新指定策略的阶段状态：自动维护对应 *_error / *_indexed_at 字段，
     * 写入后立即调用 refreshOverallStatus() 把综合状态同步到 status 列。
     *
     * Action 里只需 `$document->updateStageStatus(Vector, Processing)` 这一行，
     * 不再各自重复 forceFill + save + 派生 status 的样板。
     */
    public function updateStageStatus(
        KnowledgeIndexingStrategy $strategy,
        KnowledgeDocumentIndexingStatus $status,
        ?string $error = null,
        ?KnowledgeBase $knowledgeBase = null,
    ): self {
        $prefix = match ($strategy) {
            KnowledgeIndexingStrategy::Vector => 'vector',
            KnowledgeIndexingStrategy::Raptor => 'raptor',
            KnowledgeIndexingStrategy::Text => null,
        };

        if ($prefix === null) {
            // Text 索引状态由解析阶段反映；这里无需写库。
            return $this->refreshOverallStatus($knowledgeBase);
        }

        $updates = [
            $prefix.'_status' => $status,
            $prefix.'_error' => $error,
        ];

        if ($status === KnowledgeDocumentIndexingStatus::Succeeded) {
            $updates[$prefix.'_indexed_at'] = now();
        } elseif ($status === KnowledgeDocumentIndexingStatus::Idle
            || $status === KnowledgeDocumentIndexingStatus::Pending) {
            $updates[$prefix.'_indexed_at'] = null;
        }

        $this->forceFill($updates)->save();
        $this->refreshOverallStatus($knowledgeBase);

        return $this;
    }

    /**
     * 从数据库最新阶段状态派生 `status` 列。
     * 先 refresh 一次以兼容并发写场景；调用方在多次 updateStageStatus 后无需再单独调用。
     */
    public function refreshOverallStatus(?KnowledgeBase $knowledgeBase = null): self
    {
        $knowledgeBase ??= $this->knowledgeBase;
        if ($knowledgeBase === null) {
            return $this;
        }

        $this->refresh();
        $this->forceFill(['status' => $this->deriveOverallStatus($knowledgeBase)])->save();

        return $this;
    }

    /**
     * 根据 parse 与各启用策略状态派生综合状态：
     *  - parse 失败 / 任一启用策略失败 → Failed
     *  - parse 未完成 → Pending / Parsing
     *  - parse 完成但启用策略全部 Idle → Parsed
     *  - 任一启用策略 Pending/Processing → Indexing
     *  - 所有启用策略 Succeeded → Indexed
     */
    public function deriveOverallStatus(KnowledgeBase $knowledgeBase): KnowledgeDocumentStatus
    {
        if ($this->parse_status === KnowledgeDocumentParseStatus::Failed) {
            return KnowledgeDocumentStatus::Failed;
        }

        if ($this->parse_status === KnowledgeDocumentParseStatus::Pending) {
            return KnowledgeDocumentStatus::Pending;
        }

        if ($this->parse_status === KnowledgeDocumentParseStatus::Processing) {
            return KnowledgeDocumentStatus::Parsing;
        }

        $strategies = $knowledgeBase->enabledIndexingStrategies();
        if ($strategies === []) {
            return KnowledgeDocumentStatus::Indexed;
        }

        $hasFailed = false;
        $hasPending = false;
        $allSucceeded = true;
        $anyTouched = false;
        foreach ($strategies as $strategy) {
            $status = $this->indexingStatusFor($strategy);

            if ($status === KnowledgeDocumentIndexingStatus::Failed) {
                $hasFailed = true;
            }

            if ($status === KnowledgeDocumentIndexingStatus::Pending
                || $status === KnowledgeDocumentIndexingStatus::Processing) {
                $hasPending = true;
                $anyTouched = true;
            }

            if ($status === KnowledgeDocumentIndexingStatus::Succeeded) {
                $anyTouched = true;
            } else {
                $allSucceeded = false;
            }
        }

        if ($hasFailed) {
            return KnowledgeDocumentStatus::Failed;
        }

        if ($hasPending) {
            return KnowledgeDocumentStatus::Indexing;
        }

        if ($allSucceeded) {
            return KnowledgeDocumentStatus::Indexed;
        }

        return $anyTouched ? KnowledgeDocumentStatus::Indexing : KnowledgeDocumentStatus::Parsed;
    }

    /**
     * 文档归属的工作区。
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * 文档归属的知识库。
     */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    /**
     * 文档所在的分组。
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(KnowledgeGroup::class, 'group_id');
    }

    /**
     * 上传文档的用户。
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id')->withTrashed();
    }

    /**
     * 文档上传时保留下来的原始文件对象。
     */
    public function originalFile(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->where('purpose', AttachmentPurpose::KnowledgeDocument);
    }
}
