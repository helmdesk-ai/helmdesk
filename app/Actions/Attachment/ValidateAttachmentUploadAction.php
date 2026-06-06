<?php

namespace App\Actions\Attachment;

use App\Enums\AttachmentPurpose;
use App\Enums\AttachmentVisibility;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 校验附件上传用途对应的大小、类型和可见性规则。
 */
class ValidateAttachmentUploadAction
{
    use AsAction;

    /**
     * @var list<string>
     */
    private const BLOCKED_MIME_TYPES = [
        'text/html',
        'application/javascript',
        'text/javascript',
        'application/x-msdownload',
        'application/x-sh',
        'application/x-php',
    ];

    /**
     * 返回去掉参数后缀并转小写的 MIME 类型。
     */
    public static function normalizeMimeType(string $mimeType): string
    {
        return strtolower(trim(explode(';', $mimeType)[0]));
    }

    /**
     * 校验文件类型和大小，并返回当前用途的上传规则。
     *
     * @return array{visibility: AttachmentVisibility, max_size: int, mime_types: list<string>}
     */
    public function handle(AttachmentPurpose $purpose, string $mimeType, int $byteSize): array
    {
        $rule = $this->ruleForPurpose($purpose);
        $normalizedMime = self::normalizeMimeType($mimeType);

        if (in_array($normalizedMime, self::BLOCKED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'mime_type' => __('attachments.errors.blocked_mime'),
            ]);
        }

        if ($byteSize <= 0 || $byteSize > $rule['max_size']) {
            throw ValidationException::withMessages([
                'byte_size' => __('validation.max.file', ['max' => (int) ceil($rule['max_size'] / 1024)]),
            ]);
        }

        if ($rule['mime_types'] !== [] && ! in_array($normalizedMime, $rule['mime_types'], true)) {
            throw ValidationException::withMessages([
                'mime_type' => __('validation.mimes', ['values' => implode(', ', $rule['mime_types'])]),
            ]);
        }

        return $rule;
    }

    /**
     * 返回指定用途对应的可见性、大小和类型规则。
     *
     * @return array{visibility: AttachmentVisibility, max_size: int, mime_types: list<string>}
     */
    private function ruleForPurpose(AttachmentPurpose $purpose): array
    {
        return match ($purpose) {
            AttachmentPurpose::Avatar, AttachmentPurpose::ChannelIcon => [
                'visibility' => AttachmentVisibility::Public,
                'max_size' => 2 * 1024 * 1024,
                'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            ],
            AttachmentPurpose::ConversationImage => [
                'visibility' => AttachmentVisibility::Private,
                'max_size' => 10 * 1024 * 1024,
                'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            ],
            AttachmentPurpose::ConversationFile => [
                'visibility' => AttachmentVisibility::Private,
                'max_size' => 50 * 1024 * 1024,
                'mime_types' => [],
            ],
            AttachmentPurpose::Import => [
                'visibility' => AttachmentVisibility::Private,
                'max_size' => 100 * 1024 * 1024,
                'mime_types' => ['text/csv', 'application/json', 'text/plain', 'application/pdf'],
            ],
            AttachmentPurpose::Other => [
                'visibility' => AttachmentVisibility::Private,
                'max_size' => 10 * 1024 * 1024,
                'mime_types' => ['application/pdf', 'text/plain', 'image/jpeg', 'image/png', 'image/webp'],
            ],
        };
    }
}
