<?php

namespace App\Data\Reception\Plan;

use Spatie\LaravelData\Data;

/**
 * 接待方案单日人工服务时间。
 */
class ReceptionBusinessHoursDayData extends Data
{
    /**
     * 创建单日人工服务时间配置。
     *
     * $day 遵循 ISO 周次：1=周一 … 7=周日。
     */
    public function __construct(
        public int $day,
        public bool $enabled,
        public string $open,
        public string $close,
    ) {}
}
