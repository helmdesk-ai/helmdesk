<?php

namespace App\Actions\Attachment;

use App\Enums\AttachmentUploadMode;
use App\Enums\AttachmentUploadStatus;
use App\Models\AttachmentUpload;
use App\Policies\AttachmentAccessPolicy;
use App\Services\Storage\AttachmentAccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 接收本地代理上传的文件并写入私有存储。
 */
class ProxyAttachmentUploadAction
{
    use AsAction;

    /**
     * 注入附件访问判断策略。
     */
    public function __construct(
        private readonly AttachmentAccessPolicy $access,
    ) {}

    /**
     * 校验上传控制权并把代理上传文件写入本地私有磁盘。
     */
    public function handle(AttachmentUpload $upload, AttachmentAccessContext $context, UploadedFile $file): AttachmentUpload
    {
        $upload->loadMissing('attachment');

        if (! $this->access->canControlUpload($context, $upload)) {
            abort(403);
        }

        if ($upload->mode !== AttachmentUploadMode::Proxy) {
            throw ValidationException::withMessages(['upload' => __('attachments.errors.invalid_upload_mode')]);
        }

        if ($upload->expires_at->isPast()) {
            throw ValidationException::withMessages(['upload' => __('attachments.errors.upload_expired')]);
        }

        if ($file->getSize() !== $upload->expected_byte_size) {
            throw ValidationException::withMessages(['file' => __('attachments.errors.object_mismatch')]);
        }

        $stream = fopen($file->getRealPath(), 'rb');
        Storage::disk('local')->put($upload->object_key, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $upload->update(['status' => AttachmentUploadStatus::Uploading]);

        return $upload->fresh();
    }

    /**
     * 接收代理上传文件并返回上传意图状态。
     */
    public function asController(Request $request, AttachmentUpload $upload): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('validation.required', ['attribute' => 'file'])]);
        }

        $upload = $this->handle($upload, AttachmentAccessContext::fromRequest($request), $file);

        return response()->json([
            'upload' => [
                'id' => (string) $upload->id,
                'status' => $upload->status->value,
            ],
        ]);
    }
}
