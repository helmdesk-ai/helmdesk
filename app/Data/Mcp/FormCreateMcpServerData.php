<?php

namespace App\Data\Mcp;

use App\Enums\McpTransport;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * 创建 MCP 服务表单数据。
 * 来自 resources/js/pages/systemSettings/mcpServers/Create.vue 的提交。
 *
 * 认证统一为一对 header：
 *  - 都填即提交认证配置；
 *  - 都不填即无认证；
 *  - 只填一个会触发校验错误（不允许半配置）。
 *
 * UI 上的 "Bearer Token" preset 由前端拼成 `Authorization` + `Bearer <token>` 后提交，
 * 后端不感知 preset 概念。
 */
class FormCreateMcpServerData extends Data
{
    public function __construct(
        public string $name,
        public string $endpoint_url,
        public McpTransport $transport,
        public ?string $auth_header_name = null,
        public ?string $auth_header_value = null,
        /** @var array<string, string>|null */
        public ?array $headers = null,
        public ?int $timeout_seconds = null,
    ) {}

    /**
     * 表单校验规则：endpoint 必填 http(s) URL，认证 header name/value 不能只填一个。
     *
     * 由于 `nullable` 会让 Laravel 跳过 `required_with`，这里改成根据当前请求的填值情况
     * 动态决定哪一边变成强制 `required`，从而既允许"都空"，也阻止"只填一边"。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($context->payload) ? $context->payload : [];
        $hasName = filled($payload['auth_header_name'] ?? null);
        $hasValue = filled($payload['auth_header_value'] ?? null);

        $rules = [
            'name' => ['required', 'string', 'max:128'],
            'endpoint_url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'transport' => ['required', Rule::enum(McpTransport::class)],
            'auth_header_name' => ['nullable', 'string', 'max:128'],
            'auth_header_value' => ['nullable', 'string', 'max:4096'],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['string', 'max:4096'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
        ];

        if ($hasValue && ! $hasName) {
            $rules['auth_header_name'] = ['required', 'string', 'max:128'];
        }
        if ($hasName && ! $hasValue) {
            $rules['auth_header_value'] = ['required', 'string', 'max:4096'];
        }

        return $rules;
    }
}
