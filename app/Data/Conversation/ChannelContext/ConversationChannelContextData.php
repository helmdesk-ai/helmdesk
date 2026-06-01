<?php

namespace App\Data\Conversation\ChannelContext;

use Spatie\LaravelData\Data;

/**
 * 会话渠道上下文的多态基类（标记类型）。
 *
 * 不同渠道携带的上下文结构不同：Web 是访客行为（页面/来源/设备/地理），
 * Telegram 是用户元数据。各变体自带 channel_type 判别字段，
 * 由 App\Casts\ConversationChannelContextCast 据此分流反序列化。
 * 坐席侧前端按 channel_type 收窄展示。
 */
abstract class ConversationChannelContextData extends Data {}
