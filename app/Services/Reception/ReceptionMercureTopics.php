<?php

namespace App\Services\Reception;

/**
 * 生成接待模块使用的 Mercure topic。
 */
class ReceptionMercureTopics
{
    /**
     * 生成后台收件箱接待 topic。
     */
    public static function inbox(): string
    {
        return 'urn:helmdesk:reception:inbox';
    }

    /**
     * 生成单个会话接待 topic。
     */
    public static function conversation(string $conversationId): string
    {
        return 'urn:helmdesk:reception:conversation:'.$conversationId;
    }
}
