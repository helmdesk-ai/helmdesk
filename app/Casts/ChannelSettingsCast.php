<?php

namespace App\Casts;

use App\Data\Channel\Telegram\ChannelTelegramSettingsData;
use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Enums\ChannelType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * 渠道 settings JSON 列的类型分流 cast。
 *
 * 不同渠道类型的设置结构不同（网站 vs Telegram），按 channels.type 选择对应的 Data 类反序列化；
 * JSON 中缺省的字段由各 Data 的构造函数默认值补齐。
 *
 * @implements CastsAttributes<Data, Data>
 */
class ChannelSettingsCast implements CastsAttributes
{
    /**
     * 按渠道类型把 JSON 列反序列化为对应的设置 Data。
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Data
    {
        $decoded = $this->decode($value);

        return match ($this->resolveType($attributes)) {
            ChannelType::Telegram => ChannelTelegramSettingsData::from($decoded),
            ChannelType::Web => ChannelWebSettingsData::from($decoded),
        };
    }

    /**
     * 把设置 Data / 数组序列化为 JSON 字符串写回数据库。
     *
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof Data) {
            return [$key => $value->toJson()];
        }

        if (is_array($value)) {
            return [$key => json_encode($value)];
        }

        return [$key => $value];
    }

    /**
     * 从模型原始属性解析渠道类型；类型缺失或非法时直接失败，不静默回退。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resolveType(array $attributes): ChannelType
    {
        $type = $attributes['type'] ?? null;

        if ($type instanceof ChannelType) {
            return $type;
        }

        return ChannelType::from((string) $type);
    }

    /**
     * 把 JSON 列原始值解码为关联数组。
     *
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
