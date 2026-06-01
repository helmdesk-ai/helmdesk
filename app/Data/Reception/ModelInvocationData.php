<?php

namespace App\Data\Reception;

use Spatie\LaravelData\Data;

/**
 * 接待方案中一个模型角色的引用。
 * 仅持 ai_model_id 引用；凭据 / endpoint 由 Go 在调用前实时取。
 * label 用于只读展示场景，避免前端再维护映射。
 */
class ModelInvocationData extends Data
{
    public function __construct(
        public string $ai_model_id,
        public ?string $label = null,
    ) {}

    /**
     * 从 reception/task 设置块中的 default_model 构造数据。
     *
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): ?self
    {
        if ($raw === null || ! isset($raw['ai_model_id']) || ! is_string($raw['ai_model_id']) || $raw['ai_model_id'] === '') {
            return null;
        }

        return new self(
            ai_model_id: (string) $raw['ai_model_id'],
        );
    }
}
