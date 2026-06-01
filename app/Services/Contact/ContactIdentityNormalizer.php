<?php

namespace App\Services\Contact;

use App\Enums\IdentityType;

/**
 * 规范化联系人身份标识。
 */
class ContactIdentityNormalizer
{
    /**
     * 检查手机号输入格式是否像国际号码。
     */
    public static function isPhoneInputFormatValid(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        return preg_match('/^\+[0-9\s().-]+$/', $value) === 1;
    }

    /**
     * 检查标准化后的手机号是否有效。
     */
    public static function isNormalizedPhoneValid(string $value): bool
    {
        return preg_match('/^\+[1-9]\d{5,14}$/', $value) === 1;
    }

    /**
     * 按身份类型标准化输入值。
     */
    public static function normalizeValue(IdentityType $type, string $value): string
    {
        $value = trim($value);

        return match ($type) {
            IdentityType::Email => strtolower($value),
            IdentityType::Phone => self::normalizePhone($value),
            default => $value,
        };
    }

    /**
     * 生成身份标识的展示值。
     */
    public static function buildDisplayValue(IdentityType $type, string $value): ?string
    {
        return match ($type) {
            IdentityType::Session => 'sess:'.substr($value, 0, 8),
            default => $value,
        };
    }

    /**
     * 判断身份类型是否能把访客提升为联系人。
     */
    public static function promotesContactType(IdentityType $type): bool
    {
        return in_array($type, [
            IdentityType::Email,
            IdentityType::Phone,
            IdentityType::ExternalId,
        ], true);
    }

    /**
     * 把手机号压成 E.164 风格。
     */
    private static function normalizePhone(string $value): string
    {
        $normalized = preg_replace('/[^\d+]+/', '', trim($value)) ?? '';

        if (! str_starts_with($normalized, '+')) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', substr($normalized, 1)) ?? '';

        if ($digits === '') {
            return '';
        }

        return '+'.$digits;
    }
}
