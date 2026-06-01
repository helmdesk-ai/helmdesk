<?php

namespace App\Actions\Attachment;

use App\Enums\AttachmentUploadMode;
use App\Models\AttachmentUpload;
use App\Policies\AttachmentAccessPolicy;
use App\Services\Storage\AttachmentAccessContext;
use App\Services\Storage\AttachmentUploadSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 为分片上传生成指定分片的预签名地址。
 */
class SignAttachmentUploadPartAction
{
    use AsAction;

    /**
     * 注入附件访问判断策略和上传签名服务。
     */
    public function __construct(
        private readonly AttachmentAccessPolicy $access,
        private readonly AttachmentUploadSigner $signer,
    ) {}

    /**
     * 校验上传控制权并为指定分片生成预签名地址。
     *
     * @param  list<int>  $parts
     * @return array<string, mixed>
     */
    public function handle(AttachmentUpload $upload, AttachmentAccessContext $context, array $parts): array
    {
        if (! $this->access->canControlUpload($context, $upload)) {
            abort(403);
        }

        if ($upload->mode !== AttachmentUploadMode::Multipart) {
            throw ValidationException::withMessages(['upload' => __('attachments.errors.invalid_upload_mode')]);
        }

        if (count($parts) > 20) {
            throw ValidationException::withMessages(['parts' => __('validation.max.array', ['max' => 20])]);
        }

        return $this->signer->signParts($upload, $parts);
    }

    /**
     * 接收分片号列表并返回对应预签名地址。
     */
    public function asController(Request $request, AttachmentUpload $upload): JsonResponse
    {
        $validated = $request->validate([
            'parts' => ['required', 'array', 'min:1', 'max:20'],
            'parts.*' => ['integer', 'min:1', 'max:10000'],
        ]);

        return response()->json($this->handle($upload, AttachmentAccessContext::fromRequest($request), $validated['parts']));
    }
}
