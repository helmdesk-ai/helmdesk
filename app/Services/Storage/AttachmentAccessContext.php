<?php

namespace App\Services\Storage;

use App\Models\User;
use App\Services\Reception\ReceptionSession;
use Illuminate\Http\Request;

/**
 * 附件访问上下文，承载请求侧已解析好的用户和访客会话状态。
 */
class AttachmentAccessContext
{
    /**
     * @param  list<User>  $users
     * @param  array<string, string>  $visitorTokensByCookie
     */
    public function __construct(
        public readonly array $users = [],
        public readonly array $visitorTokensByCookie = [],
        public readonly ?string $visitorTokenFromHeader = null,
    ) {}

    /**
     * 从 HTTP 请求中提取附件上传控制所需的最小上下文。
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            users: self::authenticatedUsers($request),
            visitorTokensByCookie: self::visitorTokensByCookie($request),
            visitorTokenFromHeader: self::visitorTokenFromHeader($request),
        );
    }

    /**
     * 返回第一个已认证用户，供上传创建入口归属附件使用。
     */
    public function firstUser(): ?User
    {
        return $this->users[0] ?? null;
    }

    /**
     * 返回所有有效访客 token，供上传意图控制权校验使用。
     *
     * @return list<string>
     */
    public function visitorTokens(): array
    {
        return array_values(array_unique(array_filter([
            ...array_values($this->visitorTokensByCookie),
            $this->visitorTokenFromHeader,
        ])));
    }

    /**
     * 按渠道 code 读取对应访客 token。
     */
    public function visitorTokenForChannel(?string $channelCode): ?string
    {
        if (! filled($channelCode)) {
            return null;
        }

        return $this->visitorTokensByCookie[ReceptionSession::COOKIE_PREFIX.$channelCode]
            ?? $this->visitorTokenFromHeader;
    }

    /**
     * 收集当前请求里所有已认证 guard 的用户。
     *
     * @return list<User>
     */
    private static function authenticatedUsers(Request $request): array
    {
        return collect([
            $request->user('web'),
            $request->user('admin'),
            $request->user('sanctum'),
        ])
            ->filter(fn (mixed $user): bool => $user instanceof User)
            ->unique(fn (User $user): string => (string) $user->id)
            ->values()
            ->all();
    }

    /**
     * 收集所有合法的访客接待 cookie。
     *
     * @return array<string, string>
     */
    private static function visitorTokensByCookie(Request $request): array
    {
        return collect($request->cookies->all())
            ->filter(fn (mixed $value, string $name): bool => is_string($value) && str_starts_with($name, ReceptionSession::COOKIE_PREFIX))
            ->map(fn (string $value): ?string => ReceptionSession::normalize($value))
            ->filter()
            ->all();
    }

    /**
     * 读取独立页显式传入的访客 token。
     */
    private static function visitorTokenFromHeader(Request $request): ?string
    {
        return ReceptionSession::normalize($request->header('X-Helmdesk-Visitor-Token'));
    }
}
