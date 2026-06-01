<?php

namespace App\Services\KnowledgeBase;

use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 把知识库文档的原始内容物化到本地磁盘，给 PHP 端 DocumentParserManager 按 file_path 解析。
 *
 * Upload 类型：从附件 disk 拉流写到 storage/app/private/knowledge-temp/{doc}-{ulid}.{ext}。
 * Manual 类型：直接把 content 字段写成 .md 文件。
 */
class KnowledgeDocumentSourceMaterializer
{
    /**
     * 物化结果：包含临时文件绝对路径与是否需要清理。
     *
     * @return array{
     *     path: string,
     *     should_cleanup: bool,
     *     mime_type: ?string,
     *     extension: ?string,
     * }
     */
    public function materialize(KnowledgeDocument $document): array
    {
        $document->loadMissing('originalFile');

        if ($document->originalFile) {
            $attachment = $document->originalFile;
            $extension = (string) ($attachment->extension ?: $document->extension ?: 'bin');
            $path = $this->createTempPath((string) $document->id, $extension);

            $source = $attachment->filesystem()->readStream($attachment->object_key);
            if (! is_resource($source)) {
                throw new RuntimeException(sprintf(
                    'Failed to open attachment stream for knowledge document [%s].',
                    $document->id,
                ));
            }

            $dest = fopen($path, 'wb');
            if (! is_resource($dest)) {
                fclose($source);
                throw new RuntimeException(sprintf(
                    'Failed to allocate temporary file at [%s].',
                    $path,
                ));
            }

            try {
                stream_copy_to_stream($source, $dest);
            } finally {
                fclose($source);
                fclose($dest);
            }

            return [
                'path' => $path,
                'should_cleanup' => true,
                'mime_type' => (string) ($attachment->mime_type ?: $document->mime_type),
                'extension' => $extension,
            ];
        }

        $content = (string) $document->content;
        if ($content === '') {
            throw new RuntimeException(sprintf(
                'Knowledge document [%s] has no original file and empty content.',
                $document->id,
            ));
        }

        $extension = (string) ($document->extension ?: 'md');
        $path = $this->createTempPath((string) $document->id, $extension);
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf(
                'Failed to write manual document content to [%s].',
                $path,
            ));
        }

        return [
            'path' => $path,
            'should_cleanup' => true,
            'mime_type' => (string) ($document->mime_type ?: 'text/markdown'),
            'extension' => $extension,
        ];
    }

    /**
     * 清理临时文件；不存在时静默忽略，存在但删除失败仅记录 warning（不影响主流程）。
     */
    public function cleanup(string $path): void
    {
        if ($path === '' || ! is_file($path)) {
            return;
        }

        if (! @unlink($path)) {
            Log::warning('Failed to remove knowledge document temp file.', ['path' => $path]);
        }
    }

    /**
     * 生成位于 storage/app/private/knowledge-temp 下的临时文件路径，并保证父目录存在。
     */
    private function createTempPath(string $documentId, string $extension): string
    {
        $dir = storage_path('app/private/knowledge-temp');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Failed to create temp dir [%s].', $dir));
        }

        $extension = preg_replace('/[^a-zA-Z0-9]/', '', $extension) ?: 'bin';
        $unique = bin2hex(random_bytes(8));

        return $dir.DIRECTORY_SEPARATOR.$documentId.'-'.$unique.'.'.$extension;
    }
}
