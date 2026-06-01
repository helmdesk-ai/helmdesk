<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 为每个 HTTP 请求注入关联 ID（request_id / trace_id）到日志上下文。
 *
 * request_id 标识单次请求；trace_id 用于跨服务串联——优先复用上游（如 Go 网关/采集层）
 * 传入的 trace 头，缺失时回落到 request_id。两者写入 Context 后由 UnifiedJsonFormatter 落到日志顶层，
 * 并通过响应头回传 request_id 方便客户端与服务端对账。
 */
class AssignRequestContext
{
    /**
     * 生成/透传关联 ID 并写入日志上下文，处理完请求后回写响应头。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $traceId = $request->headers->get('X-Helmdesk-Trace-Id') ?: $requestId;

        Context::add('request_id', $requestId);
        Context::add('trace_id', $traceId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
