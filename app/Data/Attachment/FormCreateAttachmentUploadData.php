<?php

namespace App\Data\Attachment;

use App\Actions\Attachment\ValidateAttachmentUploadAction;
use App\Enums\AttachmentPurpose;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 创建附件上传意图的表单数据，来自头像、Logo、聊天附件等前端上传入口。
 */
class FormCreateAttachmentUploadData extends Data
{
    /**
     * 承载创建上传意图时提交的文件信息和业务上下文。
     *
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public AttachmentPurpose $purpose,
        public string $file_name,
        public string $mime_type,
        public int $byte_size,
        public ?string $checksum_sha256 = null,
        public array $context = [],
    ) {}

    /**
     * 返回创建上传意图表单的验证规则。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'purpose' => ['required', 'string', Rule::enum(AttachmentPurpose::class)],
            'file_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:120'],
            'byte_size' => ['required', 'integer', 'min:1'],
            'checksum_sha256' => ['nullable', 'string', 'size:64'],
            'context' => ['sometimes', 'array'],
        ];
    }

    /**
     * 返回去掉参数后缀并转小写的 MIME 类型。
     */
    public function normalizedMimeType(): string
    {
        return ValidateAttachmentUploadAction::normalizeMimeType($this->mime_type);
    }
}
