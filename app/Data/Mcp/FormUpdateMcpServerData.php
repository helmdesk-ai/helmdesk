<?php

namespace App\Data\Mcp;

use Spatie\LaravelData\Data;

/**
 * 编辑 MCP 服务表单数据。
 * 来自 resources/js/pages/systemSettings/mcpServers/Edit.vue 的连接配置保存。
 *
 * 认证 header 合并语义（敏感字段不会随表单回显，所以采用"留空 = 保留原值"约定）：
 *  - clear_auth_credentials = true：显式清空整组认证 header（用户切到"无认证" preset）；
 *  - 否则 auth_header_name/value 都为 null/缺失：保留原值；
 *  - 任一为非空字符串：用前端送上来的对覆盖；
 *  - 合并后任一为空，整组认证 header 一并清空。
 */
class FormUpdateMcpServerData extends Data
{
    public function __construct(
        public string $name,
        public string $endpoint_url,
        public ?string $auth_header_name = null,
        public ?string $auth_header_value = null,
        public bool $clear_auth_credentials = false,
        /** @var array<string, string>|null */
        public ?array $headers = null,
        public ?int $timeout_seconds = null,
    ) {}

    /**
     * 更新表单校验规则。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128'],
            'endpoint_url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'auth_header_name' => ['nullable', 'string', 'max:128'],
            'auth_header_value' => ['nullable', 'string', 'max:4096'],
            'clear_auth_credentials' => ['nullable', 'boolean'],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['string', 'max:4096'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
        ];
    }
}
