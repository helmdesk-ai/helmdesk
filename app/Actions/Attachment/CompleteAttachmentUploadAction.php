<?php

namespace App\Actions\Attachment;

use App\Data\Attachment\FormCompleteAttachmentUploadData;
use App\Data\Attachment\UploadedAttachmentData;
use App\Models\Attachment;
use App\Models\AttachmentUpload;
use App\Policies\AttachmentAccessPolicy;
use App\Services\Storage\AttachmentAccessContext;
use App\Services\Storage\AttachmentUploadCompleter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 完成附件上传并返回可展示的附件信息。
 */
class CompleteAttachmentUploadAction
{
    use AsAction;

    /**
     * 注入附件访问判断策略和上传完成服务。
     */
    public function __construct(
        private readonly AttachmentAccessPolicy $access,
        private readonly AttachmentUploadCompleter $completer,
    ) {}

    /**
     * 校验上传控制权并完成上传意图。
     */
    public function handle(AttachmentUpload $upload, AttachmentAccessContext $context, FormCompleteAttachmentUploadData $data): Attachment
    {
        if (! $this->access->canControlUpload($context, $upload)) {
            abort(403);
        }

        return $this->completer->complete($upload, $data->parts, $data->checksum_sha256);
    }

    /**
     * 接收上传完成请求并返回附件展示数据。
     */
    public function asController(Request $request, AttachmentUpload $upload): JsonResponse
    {
        $attachment = $this->handle(
            upload: $upload,
            context: AttachmentAccessContext::fromRequest($request),
            data: FormCompleteAttachmentUploadData::from($request),
        );

        return response()->json([
            'attachment' => UploadedAttachmentData::fromModel($attachment)->toArray(),
        ]);
    }
}
