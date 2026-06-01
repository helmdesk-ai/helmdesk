<?php

namespace App\Services\Reception;

/**
 * 生成接待模块使用的 Mercure topic。
 */
class ReceptionMercureTopics
{
    /**
     * 生成工作区接待 topic。
     */
    public static function workspace(string $workspaceId): string
    {
        return 'urn:helmdesk:reception:workspace:'.$workspaceId;
    }

    /**
     * 生成单个会话接待 topic。
     */
    public static function conversation(string $conversationId): string
    {
        return 'urn:helmdesk:reception:conversation:'.$conversationId;
    }
}
