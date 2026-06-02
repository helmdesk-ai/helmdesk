<?php

namespace App\Data\KnowledgeBase;

use App\Enums\KnowledgeIndexingStrategy;
use Spatie\LaravelData\Data;

/**
 * 知识库索引策略选项。
 *
 * 用于在知识库创建/编辑表单上渲染增强索引多选项。
 */
class KnowledgeIndexingStrategyOptionData extends Data
{
    /**
     * 创建前端索引策略选项。
     */
    public function __construct(
        public string $value,
        public string $label,
        public string $description,
        public bool $requires_summary_model,
    ) {}

    /**
     * 从索引策略枚举构造选项。
     */
    public static function fromEnum(KnowledgeIndexingStrategy $strategy): self
    {
        return new self(
            value: $strategy->value,
            label: $strategy->label(),
            description: $strategy->description(),
            requires_summary_model: $strategy === KnowledgeIndexingStrategy::Raptor,
        );
    }

    /**
     * 系统可勾选启用的索引策略选项；Text 始终启用，不在前端表单中暴露。
     *
     * @return list<self>
     */
    public static function options(): array
    {
        return array_map(
            static fn (KnowledgeIndexingStrategy $strategy) => self::fromEnum($strategy),
            KnowledgeIndexingStrategy::togglableCases(),
        );
    }
}
