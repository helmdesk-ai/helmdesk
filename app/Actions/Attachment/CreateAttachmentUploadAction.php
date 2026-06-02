<?php

namespace App\Actions\Attachment;

use App\Data\Attachment\AttachmentDirectUploadData;
use App\Data\Attachment\AttachmentUploadData;
use App\Data\Attachment\AttachmentUploadIntentData;
use App\Data\Attachment\FormCreateAttachmentUploadData;
use App\Data\Attachment\UploadedAttachmentData;
use App\Enums\AttachmentPurpose;
use App\Enums\AttachmentStatus;
use App\Enums\AttachmentUploadMode;
use App\Enums\AttachmentUploadStatus;
use App\Enums\StorageDriver;
use App\Models\Attachment;
use App\Models\AttachmentUpload;
use App\Models\Channel;
use App\Services\Reception\ReceptionSession;
use App\Services\Storage\AttachmentAccessContext;
use App\Services\Storage\AttachmentPathGenerator;
use App\Services\Storage\AttachmentUploadSigner;
use App\Services\Storage\StorageProfileResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 创建附件上传意图，并返回浏览器直传或服务端代理上传所需参数。
 */
class CreateAttachmentUploadAction
{
    use AsAction;

    /**
     * 注入存储配置、上传校验、路径生成和签名服务。
     */
    public function __construct(
        private readonly StorageProfileResolver $profileResolver,
        private readonly ValidateAttachmentUploadAction $uploadValidator,
        private readonly AttachmentPathGenerator $pathGenerator,
        private readonly AttachmentUploadSigner $signer,
    ) {}

    /**
     * 创建附件占位记录和上传意图，并返回前端上传所需参数。
     */
    public function handle(
        FormCreateAttachmentUploadData $data,
        AttachmentAccessContext $context,
        ?string $clientIp = null,
        ?string $userAgent = null,
        bool $preferVisitorSession = false,
    ): AttachmentUploadIntentData {
        $mimeType = $data->normalizedMimeType();
        $rule = $this->uploadValidator->handle($data->purpose, $mimeType, $data->byte_size);
        [, $userId, $sessionToken] = $this->resolveUploadActor($context, $data->context, $data->purpose, $preferVisitorSession);
        $profile = $this->profileResolver->resolveForNewUpload();
        $driver = $profile->driver;

        $attachmentId = (string) Str::ulid();
        $objectKey = $this->pathGenerator->generate($attachmentId, $data->purpose, $data->file_name, $mimeType);
        $mode = $this->initialMode($driver, $profile->metadata ?? [], $rule['multipart_threshold'], $data->byte_size);
        $expiresAt = now()->addHour();

        /** @var AttachmentUpload $upload */
        $upload = DB::transaction(function () use (
            $attachmentId,
            $profile,
            $driver,
            $objectKey,
            $data,
            $mimeType,
            $rule,
            $userId,
            $sessionToken,
            $mode,
            $expiresAt,
            $clientIp,
            $userAgent,
        ): AttachmentUpload {
            Attachment::query()->create([
                'id' => $attachmentId,
                'uploaded_by_user_id' => $userId,
                'storage_profile_id' => $profile->id,
                'disk' => $driver,
                'bucket' => $profile->bucket,
                'object_key' => $objectKey,
                'original_name' => $data->file_name,
                'mime_type' => $mimeType,
                'extension' => $this->pathGenerator->extension($data->file_name, $mimeType),
                'byte_size' => $data->byte_size,
                'checksum_sha256' => $data->checksum_sha256,
                'visibility' => $rule['visibility'],
                'purpose' => $data->purpose,
                'status' => AttachmentStatus::Pending,
                'metadata' => [],
                'expires_at' => $expiresAt,
            ]);

            return AttachmentUpload::query()->create([
                'attachment_id' => $attachmentId,
                'storage_profile_id' => $profile->id,
                'mode' => $mode,
                'status' => AttachmentUploadStatus::Pending,
                'object_key' => $objectKey,
                'expected_name' => $data->file_name,
                'expected_mime_type' => $mimeType,
                'expected_byte_size' => $data->byte_size,
                'expected_checksum_sha256' => $data->checksum_sha256,
                'created_by_user_id' => $userId,
                'session_token_hash' => $sessionToken ? hash('sha256', $sessionToken) : null,
                'client_ip' => $clientIp,
                'user_agent' => $userAgent,
                'expires_at' => $expiresAt,
            ]);
        });

        $signed = $this->signer->sign($upload);
        $upload->refresh();
        $attachment = $upload->attachment()->firstOrFail();

        return new AttachmentUploadIntentData(
            attachment: UploadedAttachmentData::fromModel($attachment, withUrls: false),
            upload: AttachmentUploadData::fromModel($upload),
            direct: AttachmentDirectUploadData::fromPayload($signed['direct'] ?? null),
        );
    }

    /**
     * 接收创建上传意图请求并返回 JSON 上传参数。
     */
    public function asController(Request $request): JsonResponse
    {
        $data = FormCreateAttachmentUploadData::from($request);

        return response()->json(
            $this->handle(
                data: $data,
                context: AttachmentAccessContext::fromRequest($request),
                clientIp: $request->ip(),
                userAgent: (string) $request->userAgent(),
                preferVisitorSession: $request->route()?->named('visitor.attachments.*') ?? false,
            )->toArray()
        );
    }

    /**
     * 根据登录用户或访客会话解析上传归属。
     *
     * @param  array<string, mixed>  $context
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function resolveUploadActor(AttachmentAccessContext $accessContext, array $context, AttachmentPurpose $purpose, bool $preferVisitorSession): array
    {
        if ($preferVisitorSession) {
            return $this->resolveVisitorUploadActor($accessContext, $context, $purpose);
        }

        $user = $accessContext->firstUser();
        if ($user !== null) {
            return [null, (string) $user->id, null];
        }

        return $this->resolveVisitorUploadActor($accessContext, $context, $purpose);
    }

    /**
     * 根据访客渠道和会话 token 解析上传归属。
     *
     * @param  array<string, mixed>  $context
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function resolveVisitorUploadActor(AttachmentAccessContext $accessContext, array $context, AttachmentPurpose $purpose): array
    {
        $channelCode = is_string($context['channel_code'] ?? null) ? $context['channel_code'] : null;
        if (! $channelCode) {
            throw ValidationException::withMessages(['context.channel_code' => __('validation.required', ['attribute' => 'channel_code'])]);
        }

        $token = ReceptionSession::normalize($context['session_token'] ?? null)
            ?? $accessContext->visitorTokenForChannel($channelCode);
        if (! $token) {
            throw ValidationException::withMessages(['session' => __('auth.unauthorized')]);
        }

        $channel = Channel::query()->where('code', $channelCode)->firstOrFail();

        if (! in_array($purpose, [AttachmentPurpose::ConversationImage, AttachmentPurpose::ConversationFile], true)) {
            throw ValidationException::withMessages(['purpose' => __('auth.unauthorized')]);
        }

        return [null, null, $token];
    }

    /**
     * 根据存储驱动、配置和文件大小选择初始上传模式。
     *
     * @param  array<string, mixed>  $metadata
     */
    private function initialMode(StorageDriver $driver, array $metadata, ?int $multipartThreshold, int $byteSize): AttachmentUploadMode
    {
        if ($driver === StorageDriver::Local) {
            return AttachmentUploadMode::Proxy;
        }

        if ($multipartThreshold !== null && $byteSize >= $multipartThreshold) {
            return AttachmentUploadMode::Multipart;
        }

        return ($metadata['direct_upload_mode'] ?? null) === AttachmentUploadMode::PresignedPut->value
            ? AttachmentUploadMode::PresignedPut
            : AttachmentUploadMode::PresignedPost;
    }
}
