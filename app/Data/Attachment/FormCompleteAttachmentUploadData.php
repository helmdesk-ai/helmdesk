<?php

namespace App\Data\Attachment;

use Spatie\LaravelData\Data;

/**
 * 完成附件上传的表单数据，承载可选的内容校验和。
 */
class FormCompleteAttachmentUploadData extends Data
{
    /**
     * 承载完成上传时提交的校验和。
     */
    public function __construct(
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
            'checksum_sha256' => ['nullable', 'string', 'size:64'],
        ];
    }
}
