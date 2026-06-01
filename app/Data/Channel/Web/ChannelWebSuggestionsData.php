<?php

namespace App\Data\Channel\Web;

use Spatie\LaravelData\Data;

/**
 * 网站渠道猜你想问设置。
 */
class ChannelWebSuggestionsData extends Data
{
    public const MaxItems = 6;

    /**
     * 创建猜你想问配置数据。
     */
    public function __construct(
        public bool $enabled = false,
        /** @var string[] */
        public array $items = [],
    ) {}

    /**
     * 创建带默认值的猜你想问配置。
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function defaults(array $overrides = []): self
    {
        return self::from(array_replace_recursive([
            'enabled' => false,
            'items' => [],
        ], $overrides));
    }
}
