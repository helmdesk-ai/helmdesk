<?php

namespace App\Actions\Attachment;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentUploadStatus;
use App\Enums\StorageDriver;
use App\Models\AttachmentUpload;
use App\Policies\AttachmentAccessPolicy;
use App\Services\Storage\AttachmentAccessContext;
use App\Services\Storage\S3ClientFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 取消附件上传意图，并清理已经写入的临时对象。
 */
class AbortAttachmentUploadAction
{
    use AsAction;

    /**
     * 注入附件访问判断策略和对象存储客户端工厂。
     */
    public function __construct(
        private readonly AttachmentAccessPolicy $access,
        private readonly S3ClientFactory $s3ClientFactory,
    ) {}

    /**
     * 取消上传意图并删除已经产生的临时对象。
     */
    public function handle(AttachmentUpload $upload, AttachmentAccessContext $context): void
    {
        $upload->loadMissing(['attachment', 'storageProfile']);

        if (! $this->access->canControlUpload($context, $upload)) {
            abort(403);
        }

        if (! $this->canAbort($upload)) {
            throw ValidationException::withMessages(['upload' => __('attachments.errors.already_attached')]);
        }

        $this->deleteRemoteUpload($upload);

        $upload->update(['status' => AttachmentUploadStatus::Aborted]);
        $upload->attachment->update(['status' => AttachmentStatus::Deleted]);
        $upload->attachment->delete();
    }

    /**
     * 判断上传意图是否仍处于可取消状态。
     */
    private function canAbort(AttachmentUpload $upload): bool
    {
        if (! in_array($upload->status, [AttachmentUploadStatus::Pending, AttachmentUploadStatus::Uploading], true)) {
            return false;
        }

        return $upload->attachment->status === AttachmentStatus::Pending
            && $upload->attachment->attachable_id === null;
    }

    /**
     * 接收取消上传请求并返回 JSON 状态。
     */
    public function asController(Request $request, AttachmentUpload $upload): JsonResponse
    {
        $this->handle($upload, AttachmentAccessContext::fromRequest($request));

        return response()->json(['ok' => true]);
    }

    /**
     * 删除本地或对象存储上的临时上传对象。
     */
    private function deleteRemoteUpload(AttachmentUpload $upload): void
    {
        if ($upload->storageProfile->driver === StorageDriver::Local) {
            $upload->attachment->filesystem()->delete($upload->object_key);

            return;
        }

        $client = $this->s3ClientFactory->make($upload->storageProfile);
        $client->deleteObject([
            'Bucket' => $upload->storageProfile->bucket,
            'Key' => $upload->object_key,
        ]);
    }
}
