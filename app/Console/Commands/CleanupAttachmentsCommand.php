<?php

namespace App\Console\Commands;

use App\Actions\Attachment\DeleteAttachmentAction;
use App\Enums\AttachmentStatus;
use App\Enums\AttachmentUploadStatus;
use App\Models\Attachment;
use App\Models\AttachmentUpload;
use Illuminate\Console\Command;

/**
 * 清理过期上传意图、未绑定附件对象和软删除附件记录。
 */
class CleanupAttachmentsCommand extends Command
{
    /** @var string 命令名称和参数签名。 */
    protected $signature = 'attachments:cleanup';

    /** @var string 命令说明。 */
    protected $description = 'Expire stale upload intents and remove stale attachment objects.';

    /**
     * 执行附件清理任务。
     */
    public function handle(): int
    {
        $expiredUploads = AttachmentUpload::query()
            ->with('attachment')
            ->whereIn('status', [AttachmentUploadStatus::Pending, AttachmentUploadStatus::Uploading])
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expiredUploads as $upload) {
            $upload->update(['status' => AttachmentUploadStatus::Expired]);

            if ($upload->attachment !== null) {
                DeleteAttachmentAction::run($upload->attachment);
            }
        }

        $orphans = Attachment::query()
            ->where('status', AttachmentStatus::Uploaded)
            ->whereNull('attachable_id')
            ->where(function ($query): void {
                $query
                    ->where('expires_at', '<', now())
                    ->orWhere('uploaded_at', '<', now()->subDay());
            })
            ->get();

        foreach ($orphans as $attachment) {
            DeleteAttachmentAction::run($attachment);
        }

        $deleted = Attachment::onlyTrashed()
            ->where('status', AttachmentStatus::Deleted)
            ->where('deleted_at', '<', now()->subDays(7))
            ->get();

        foreach ($deleted as $attachment) {
            $attachment->forceDelete();
        }

        $this->components->info(sprintf(
            'Expired uploads cleaned: %d, deleted orphan attachments: %d, purged rows: %d',
            $expiredUploads->count(),
            $orphans->count(),
            $deleted->count(),
        ));

        return self::SUCCESS;
    }
}
