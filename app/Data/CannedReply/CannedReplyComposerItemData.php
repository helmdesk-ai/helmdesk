<?php

namespace App\Data\CannedReply;

use App\Models\CannedReply;
use Spatie\LaravelData\Data;

/**
 * 收件箱回复 composer 中"快捷回复"候选项数据。
 * 由 SearchCannedRepliesForComposerAction 返回；relevance_score 是 AI 留口字段，v1 取 0，
 * v2 接入 embedding 重排时填实际值，前后端类型不变。
 */
class CannedReplyComposerItemData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $shortcut,
        public string $content,
        public bool $is_personal,
        public int $usage_count,
        public ?string $last_used_at,
        public float $relevance_score,
    ) {}

    /**
     * 从模型构造候选项；relevance_score 默认 0，由调用方按打分策略覆盖。
     */
    public static function fromModel(CannedReply $reply, float $relevanceScore = 0.0): self
    {
        return new self(
            id: (string) $reply->id,
            name: $reply->name,
            shortcut: $reply->shortcut,
            content: $reply->content,
            is_personal: $reply->user_id !== null,
            usage_count: (int) $reply->usage_count,
            last_used_at: $reply->last_used_at?->toIso8601String(),
            relevance_score: $relevanceScore,
        );
    }
}
