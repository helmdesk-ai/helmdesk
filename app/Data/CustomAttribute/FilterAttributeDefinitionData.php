<?php

namespace App\Data\CustomAttribute;

use App\Models\AttributeDefinition;
use Spatie\LaravelData\Data;

/**
 * 筛选属性定义数据。
 */
class FilterAttributeDefinitionData extends Data
{
    public function __construct(
        public string $key,
        public string $name,
        public string $type,
        /** @var array<string, mixed>|null */
        public ?array $config,
    ) {}

    public static function fromModel(AttributeDefinition $definition): self
    {
        return new self(
            key: $definition->key,
            name: $definition->name,
            type: $definition->type->value,
            config: $definition->config,
        );
    }
}
