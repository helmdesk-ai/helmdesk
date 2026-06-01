<?php

namespace App\Services\Storage;

use App\Models\Attachment;
use RuntimeException;

/**
 * 为图片附件生成 WebP 缩略图并写入附件元数据。
 */
class AttachmentThumbnailer
{
    private const MAX_SIZE = 480;

    /**
     * 注入附件缩略图路径生成器。
     */
    public function __construct(
        private readonly AttachmentPathGenerator $pathGenerator,
    ) {}

    /**
     * 为可缩略的图片附件生成 WebP 缩略图。
     */
    public function generate(?Attachment $attachment): void
    {
        if (! $attachment || ! str_starts_with($attachment->mime_type, 'image/')) {
            return;
        }

        if ($attachment->mime_type === 'image/gif') {
            return;
        }

        $attachment->loadMissing('storageProfile');
        $disk = $attachment->filesystem();
        $bytes = $disk->get($attachment->object_key);
        $dimensions = $this->dimensions($bytes);
        $thumbnail = class_exists(\Imagick::class)
            ? $this->withImagick($bytes)
            : $this->withGd($bytes);

        $thumbnailKey = $this->pathGenerator->thumbnailKey($attachment->object_key);
        $disk->put($thumbnailKey, $thumbnail);

        $attachment->update([
            'metadata' => array_merge($attachment->metadata ?? [], [
                ...$dimensions,
                'thumbnail_key' => $thumbnailKey,
                'thumbnail_mime_type' => 'image/webp',
            ]),
        ]);
    }

    /**
     * 读取图片字节中的宽高信息。
     *
     * @return array<string, int>
     */
    private function dimensions(string $bytes): array
    {
        $size = getimagesizefromstring($bytes);
        if ($size === false) {
            throw new RuntimeException('Unable to read image dimensions from attachment bytes.');
        }

        return [
            'width' => (int) $size[0],
            'height' => (int) $size[1],
        ];
    }

    /**
     * 使用 Imagick 生成 WebP 缩略图。
     */
    private function withImagick(string $bytes): string
    {
        $image = new \Imagick;
        $image->readImageBlob($bytes);
        $image->autoOrient();
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(82);
        $image->thumbnailImage(self::MAX_SIZE, self::MAX_SIZE, true);

        return $image->getImagesBlob();
    }

    /**
     * Imagick 不可用时使用 GD 生成 WebP 缩略图。
     */
    private function withGd(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new RuntimeException('GD WebP support is not available.');
        }

        $source = imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RuntimeException('Unable to decode image bytes with GD.');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1, self::MAX_SIZE / max($sourceWidth, $sourceHeight));
        $targetWidth = max(1, (int) floor($sourceWidth * $scale));
        $targetHeight = max(1, (int) floor($sourceHeight * $scale));

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($thumbnail === false) {
            imagedestroy($source);

            throw new RuntimeException('Unable to create GD thumbnail canvas.');
        }

        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($thumbnail, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled(
            $thumbnail,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        ob_start();
        $written = imagewebp($thumbnail, null, 82);
        $webp = ob_get_clean();

        imagedestroy($thumbnail);
        imagedestroy($source);

        if (! $written || ! is_string($webp) || $webp === '') {
            throw new RuntimeException('Unable to encode GD thumbnail as WebP.');
        }

        return $webp;
    }
}
