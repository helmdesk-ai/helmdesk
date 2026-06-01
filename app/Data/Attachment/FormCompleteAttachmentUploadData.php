<?php

namespace App\Data\Attachment;

use Spatie\LaravelData\Data;

/**
 * 完成附件上传的表单数据，承载分片 ETag 和可选校验和。
 */
class FormCompleteAttachmentUploadData extends Data
{
    /**
     * 承载完成上传时提交的分片信息和校验和。
     *
     * @param  list<array{part_number: int, etag: string}>  $parts
     */
    public function __construct(
        /** @var array<int,array<string,mixed>> */
        public array $parts = [],
        public ?string $checksum_sha256 = null,
    ) {}

    /**
     * 返回完成上传表单的验证规则。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'parts' => ['sometimes', 'array'],
            'parts.*.part_number' => ['required_with:parts', 'integer', 'min:1'],
            'parts.*.etag' => ['required_with:parts', 'string'],
            'checksum_sha256' => ['nullable', 'string', 'size:64'],
        ];
    }
}
