<?php

namespace App\Actions\Conversation;

use App\Enums\AiModelType;
use App\Enums\MessageKind;
use App\Enums\MessageRole;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Conversation\GoConversationSubjectBridge;
use App\Services\Realtime\ReceptionRealtimeNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * 为会话生成并保存自动主题。
 */
class GenerateConversationSubjectAction
{
    use AsAction;

    private const MAX_MESSAGES = 3;

    private const MAX_MESSAGE_LENGTH = 500;

    private const MAX_SUBJECT_LENGTH = 60;

    private const SUBJECT_WRAPPER_PATTERN = '/^[\s"\'`“”‘’。\.]+|[\s"\'`“”‘’。\.]+$/u';

    /**
     * 注入 Go AI 桥接和实时通知服务。
     */
    public function __construct(
        private readonly GoConversationSubjectBridge $bridge,
        private readonly ReceptionRealtimeNotifier $realtimeNotifier,
    ) {}

    /**
     * 根据会话前几条访客文本消息为空主题写入短标题。
     */
    public function handle(Conversation $conversation): ?string
    {
        $conversation->loadMissing('receptionPlanVersion');

        if (filled($conversation->subject)) {
            return $conversation->subject;
        }

        $messages = $this->collectVisitorMessages($conversation);
        if ($messages === []) {
            throw new RuntimeException('Conversation subject generation requires visitor text messages.');
        }

        $model = $this->resolveModel($conversation);
        $subject = $this->normalizeSubject($this->bridge->generate($model->provider, $model, $messages));
        if ($subject === null) {
            throw new RuntimeException('Conversation subject generation returned an empty subject.');
        }

        $affected = Conversation::query()
            ->whereKey($conversation->id)
            ->whereNull('subject')
            ->update([
                'subject' => $subject,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            $currentSubject = Conversation::query()->whereKey($conversation->id)->value('subject');

            return is_string($currentSubject) ? $currentSubject : null;
        }

        $updated = $conversation->fresh();
        if ($updated !== null) {
            $this->realtimeNotifier->conversationChanged($updated, 'conversation_subject_updated', [
                'subject' => $subject,
            ]);
        }

        return $subject;
    }

    /**
     * 读取会话开头的访客文本消息并裁剪输入长度。
     *
     * @return list<string>
     */
    private function collectVisitorMessages(Conversation $conversation): array
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', MessageRole::Visitor)
            ->where('kind', MessageKind::Text)
            ->whereNotNull('content')
            ->orderBy('seq_no')
            ->limit(self::MAX_MESSAGES)
            ->pluck('content')
            ->map(fn (mixed $content): string => trim(Str::limit((string) $content, self::MAX_MESSAGE_LENGTH, '')))
            ->filter(fn (string $content): bool => $content !== '')
            ->values()
            ->all();
    }

    /**
     * 按会话锁定的接待方案版本解析接待模型。
     */
    private function resolveModel(Conversation $conversation): AiModel
    {
        $version = $conversation->receptionPlanVersion;
        if ($version === null || ! is_array($version->compiled_config)) {
            throw new RuntimeException('Conversation subject generation requires a reception plan version.');
        }

        $compiled = $version->compiled_config;
        $modelId = $compiled['reception_config']['default_model']['ai_model_id'] ?? null;
        if (! is_string($modelId) || $modelId === '') {
            throw new RuntimeException('Conversation subject generation requires a reception LLM model.');
        }

        return AiModel::query()
            ->with('provider')
            ->whereKey($modelId)
            ->where('type', AiModelType::Llm->value)
            ->where('is_active', true)
            ->whereHas('provider', function (Builder $query) use ($conversation): void {
                $query
                    ->where('workspace_id', $conversation->workspace_id)
                    ->where('is_active', true);
            })
            ->firstOrFail();
    }

    /**
     * 清理模型输出中的包裹符号并裁剪标题长度。
     */
    private function normalizeSubject(?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        if (! mb_check_encoding($subject, 'UTF-8')) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($subject));
        if (! is_string($normalized)) {
            return null;
        }

        $normalized = preg_replace(self::SUBJECT_WRAPPER_PATTERN, '', $normalized);
        if (! is_string($normalized)) {
            return null;
        }

        $normalized = Str::limit($normalized, self::MAX_SUBJECT_LENGTH, '');

        return $normalized !== '' ? $normalized : null;
    }
}
