<?php

namespace App\Data\Attachment;

use Spatie\LaravelData\Data;

/**
 * 浏览器直传对象存储所需的临时参数。
 */
class AttachmentDirectUploadData extends Data
{
    /**
     * 承载前端直传对象存储所需的 URL、表单字段和请求头。
     *
     * @param  array<string, string>|null  $fields
     * @param  array<string, string>|null  $headers
     */
    public function __construct(
        public ?string $url = null,
        public ?string $method = null,
        /** @var array<string,string>|null */
        public ?array $fields = null,
        /** @var array<string,string>|null */
        public ?array $headers = null,
        public ?string $upload_id = null,
        public ?int $part_size = null,
    ) {}

    /**
     * 从上传签名 payload 创建直传参数数据。
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromPayload(?array $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        return new self(
            url: isset($payload['url']) ? (string) $payload['url'] : null,
            method: isset($payload['method']) ? (string) $payload['method'] : null,
            fields: $payload['fields'] ?? null,
            headers: $payload['headers'] ?? null,
            upload_id: isset($payload['upload_id']) ? (string) $payload['upload_id'] : null,
            part_size: isset($payload['part_size']) ? (int) $payload['part_size'] : null,
        );
    }
}
