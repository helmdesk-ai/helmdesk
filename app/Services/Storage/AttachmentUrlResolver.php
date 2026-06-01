<?php

namespace App\Services\Storage;

use App\Enums\StorageDriver;
use App\Models\Attachment;
use App\Models\StorageProfile;
use Aws\Signature\S3SignatureV4;
use DateTimeImmutable;

/**
 * 生成自包含的附件下载地址：本地附件走 Go 直出（HMAC 签名），S3 附件返回对象存储预签名 URL。
 * 签名所用过期时间和 X-Amz-Date 都对齐到 TTL/4 的时间窗，让浏览器在窗口内复用同一份缓存。
 */
class AttachmentUrlResolver
{
    private const IMAGE_TTL = 7200;

    private const FILE_TTL = 3600;

    public function __construct(
        private readonly S3ClientFactory $s3ClientFactory,
    ) {}

    /**
     * 返回附件的下载地址，图片 TTL 略长于普通文件以兼顾消息历史的浏览器缓存。
     */
    public function url(Attachment $attachment): string
    {
        return $this->build($attachment, $this->defaultTtl($attachment), thumbnail: false);
    }

    /**
     * 图片附件返回缩略图变体的预览地址；没有缩略图或不是图片时返回 null，让前端 fallback 到原图 URL。
     */
    public function previewUrl(Attachment $attachment): ?string
    {
        if (! $this->isImage($attachment) || ! $this->hasThumbnail($attachment)) {
            return null;
        }

        return $this->build($attachment, self::IMAGE_TTL, thumbnail: true);
    }

    /**
     * 返回附件默认下载地址采用的 TTL：图片用稍长 TTL，其它文件用短 TTL。
     */
    private function defaultTtl(Attachment $attachment): int
    {
        return $this->isImage($attachment) ? self::IMAGE_TTL : self::FILE_TTL;
    }

    /**
     * 按存储驱动选择下载地址生成方式。
     */
    private function build(Attachment $attachment, int $ttl, bool $thumbnail): string
    {
        $attachment->loadMissing('storageProfile');

        [$objectKey, $mimeType, $name] = $this->resolveTarget($attachment, $thumbnail);

        if ($attachment->storageProfile?->driver === StorageDriver::S3) {
            return $this->s3PresignedUrl($attachment->storageProfile, $objectKey, $mimeType, $name, $ttl);
        }

        return $this->localSignedUrl($objectKey, $mimeType, $name, $ttl);
    }

    /**
     * 用底层 SignatureV4 手动签名，把 X-Amz-Date 和 X-Amz-Expires 都锚定到时间窗，
     * 让相同附件在同一时间窗内的预签名 URL 完全相同，浏览器才能命中缓存。
     * 同时通过 ResponseContentDisposition / ResponseContentType 覆盖对象存储默认响应头，
     * 让图片走 inline 预览、其他文件保持 attachment 下载，与本地 Go 直出行为对齐。
     */
    private function s3PresignedUrl(StorageProfile $profile, string $objectKey, string $mimeType, string $name, int $ttl): string
    {
        $client = $this->s3ClientFactory->make($profile);
        $command = $client->getCommand('GetObject', [
            'Bucket' => $profile->bucket,
            'Key' => $objectKey,
            'ResponseContentType' => $mimeType,
            'ResponseContentDisposition' => $this->responseContentDisposition($mimeType, $name),
        ]);

        $expiresTimestamp = $this->alignedExpires($ttl);
        $startTimestamp = $expiresTimestamp - $ttl;

        $signer = new S3SignatureV4('s3', $profile->region ?: 'us-east-1');
        $signed = $signer->presign(
            \Aws\serialize($command),
            $client->getCredentials()->wait(),
            new DateTimeImmutable('@'.$expiresTimestamp),
            ['start_time' => new DateTimeImmutable('@'.$startTimestamp)],
        );

        return (string) $signed->getUri();
    }

    /**
     * 图片走 inline 预览，其它文件走 attachment 下载；filename 用 RFC 5987 编码，
     * 让中文等非 ASCII 文件名在下载时正确显示。
     */
    private function responseContentDisposition(string $mimeType, string $name): string
    {
        $disposition = str_starts_with($mimeType, 'image/') ? 'inline' : 'attachment';

        return sprintf("%s; filename*=UTF-8''%s", $disposition, rawurlencode($name));
    }

    /**
     * 选择实际要签名的对象 key、MIME 与文件名；缩略图变体复用图片 metadata。
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveTarget(Attachment $attachment, bool $thumbnail): array
    {
        if ($thumbnail) {
            $metadata = $attachment->metadata ?? [];
            $thumbKey = (string) $metadata['thumbnail_key'];
            $thumbMime = is_string($metadata['thumbnail_mime_type'] ?? null)
                ? $metadata['thumbnail_mime_type']
                : 'image/webp';
            $thumbName = pathinfo($attachment->original_name, PATHINFO_FILENAME).'.webp';

            return [$thumbKey, $thumbMime, $thumbName];
        }

        return [$attachment->object_key, $attachment->mime_type, $attachment->original_name];
    }

    /**
     * 生成本地附件的 HMAC 签名 URL，过期时间对齐到时间窗以便浏览器缓存命中。
     */
    private function localSignedUrl(string $key, string $mime, string $name, int $ttl): string
    {
        $params = [
            'key' => $key,
            'mime' => $mime,
            'name' => $name,
            'expires' => (string) $this->alignedExpires($ttl),
        ];
        $params['sig'] = $this->sign($params);

        return '/attachments/dl?'.http_build_query($params);
    }

    /**
     * 把过期时间对齐到 TTL/4 的网格，使相邻请求拿到稳定的 URL，便于浏览器缓存。
     */
    private function alignedExpires(int $ttl): int
    {
        $step = max(60, intdiv($ttl, 4));

        return intdiv(now()->timestamp, $step) * $step + $ttl;
    }

    /**
     * 用 length-prefix payload 计算 HMAC，避免字段值含分隔符时出现拼接歧义。
     *
     * @param  array{key: string, mime: string, name: string, expires: string}  $params
     */
    private function sign(array $params): string
    {
        $payload = sprintf(
            'v1|%d:%s|%d:%s|%d:%s|%s',
            strlen($params['key']), $params['key'],
            strlen($params['mime']), $params['mime'],
            strlen($params['name']), $params['name'],
            $params['expires'],
        );

        return hash_hmac('sha256', $payload, $this->appKey());
    }

    /**
     * 读取 APP_KEY 原始字节，base64 形式自动解码。
     */
    private function appKey(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:')
            ? (string) base64_decode(substr($key, 7))
            : $key;
    }

    /**
     * 判断附件是否为图片类型，决定 TTL 和缩略图分支。
     */
    private function isImage(Attachment $attachment): bool
    {
        return str_starts_with($attachment->mime_type, 'image/');
    }

    /**
     * 判断附件 metadata 是否带有缩略图 key。
     */
    private function hasThumbnail(Attachment $attachment): bool
    {
        return is_string(($attachment->metadata ?? [])['thumbnail_key'] ?? null);
    }
}
