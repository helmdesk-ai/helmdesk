<?php

namespace App\Console\Commands;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentUploadMode;
use App\Enums\AttachmentUploadStatus;
use App\Models\AttachmentUpload;
use App\Services\Storage\S3ClientFactory;
use Illuminate\Console\Command;

/**
 * 取消对象存储上已经过期的分片上传任务。
 */
class AbortExpiredMultipartUploadsCommand extends Command
{
    /** @var string 命令名称和参数签名。 */
    protected $signature = 'attachments:abort-expired-multipart';

    /** @var string 命令说明。 */
    protected $description = 'Abort expired multipart attachment uploads on object storage.';

    /**
     * 执行过期分片上传取消任务。
     */
    public function handle(S3ClientFactory $s3ClientFactory): int
    {
        $uploads = AttachmentUpload::query()
            ->with(['attachment', 'storageProfile'])
            ->where('mode', AttachmentUploadMode::Multipart)
            ->whereIn('status', [AttachmentUploadStatus::Pending, AttachmentUploadStatus::Uploading])
            ->whereNotNull('upload_id')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($uploads as $upload) {
            $client = $s3ClientFactory->make($upload->storageProfile);
            $client->abortMultipartUpload([
                'Bucket' => $upload->storageProfile->bucket,
                'Key' => $upload->object_key,
                'UploadId' => $upload->upload_id,
            ]);

            $upload->update(['status' => AttachmentUploadStatus::Expired]);
            $upload->attachment?->update(['status' => AttachmentStatus::Expired]);
        }

        $this->components->info('Expired multipart uploads aborted: '.$uploads->count());

        return self::SUCCESS;
    }
}
