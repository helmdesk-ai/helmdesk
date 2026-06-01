<?php

namespace App\Support\Channel;

/**
 * 网站渠道统一主题色板。
 *
 * 作为预设主题色的单一来源：既下发给渠道表单 options 渲染色板，
 * 也供 Form Data 校验复用，保证“可选项”和“校验范围”同源。
 */
class WebChannelThemePalette
{
    /**
     * 默认主题色（蓝）。
     */
    public const DEFAULT = '#2563EB';

    /**
     * 返回全部预设主题色（hex 字符串），顺序即色板展示顺序。
     *
     * @return list<string>
     */
    public static function presets(): array
    {
        return [
            self::DEFAULT, // 蓝，默认
            '#14B8A6',     // 青绿
            '#F97316',     // 橙
            '#3B82F6',     // 天蓝
            '#F59E0B',     // 琥珀
            '#B91CBA',     // 品红紫
            '#D4B106',     // 金黄
            '#7C3AED',     // 紫
            '#65A30D',     // 绿
            '#C2185B',     // 玫红
            '#020617',     // 墨黑
        ];
    }

    /**
     * 返回默认主题色。
     */
    public static function default(): string
    {
        return self::DEFAULT;
    }
}
