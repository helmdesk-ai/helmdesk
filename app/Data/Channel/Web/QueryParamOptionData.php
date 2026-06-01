<?php

namespace App\Data\Channel\Web;

use App\Enums\Channel\Web\WebChannelQueryParam;
use Spatie\LaravelData\Data;

/**
 * 查询参数选项数据。
 * 传给 resources/js/pages/channel/web/List.vue、Show.vue 及 tabs/* 的下拉框、筛选器或选择弹窗，字段保持前端选择控件需要的形状。
 */
class QueryParamOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public string $description,
    ) {}

    public static function fromEnum(WebChannelQueryParam $queryParam): self
    {
        return new self(
            value: $queryParam->value,
            label: $queryParam->label(),
            description: $queryParam->description(),
        );
    }

    /**
     * @return array<int, self>
     */
    public static function options(): array
    {
        return array_map(
            fn (WebChannelQueryParam $queryParam) => self::fromEnum($queryParam),
            WebChannelQueryParam::cases(),
        );
    }
}
