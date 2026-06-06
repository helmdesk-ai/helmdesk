/**
 * 文件说明：前端通用工具，提供页面和组合式逻辑复用的辅助能力。
 */
export function receptionInboxTopic(): string {
  return 'urn:helmdesk:reception:inbox';
}

export function receptionConversationTopic(conversationId: string): string {
  return `urn:helmdesk:reception:conversation:${conversationId}`;
}

export function openMercureEventSource(topic: string): EventSource {
  const params = new URLSearchParams();
  params.append('topic', topic);

  return new EventSource(`/.well-known/mercure?${params.toString()}`, {
    withCredentials: true,
  });
}
