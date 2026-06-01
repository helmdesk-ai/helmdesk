<?php

namespace App\Data\Reception;

use Spatie\LaravelData\Data;

/**
 * 接待状态数据。
 * 由后端组装后传给 resources/js/standalone/StandaloneRoot.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class ReceptionStateData extends Data
{
    public function __construct(
        public string $session_token,
        public string $conversation_id,
        public string $status,
        public string $assistant_name,
        public ?string $assistant_avatar_url,
        /** @var ReceptionMessageData[] */
        public array $messages,
    ) {}
}
