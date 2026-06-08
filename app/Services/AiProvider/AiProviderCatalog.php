<?php

namespace App\Services\AiProvider;

use App\Enums\AiProviderProtocol;
use InvalidArgumentException;

/**
 * AI 供应商品牌目录。
 *
 * 产品层以「品牌」为单位添加供应商（DeepSeek/Qwen/Azure 等），每个品牌预设好图标、底层协议、
 * 默认 base_url、凭据字段与内置模型；底层协议收敛为 openai/anthropic/gemini 三种原生 agentic 通道，
 * 国产/兼容品牌通过映射到这三者之一 + 预设 base_url 接入。末尾两条 custom-* 给高级用户自填端点。
 */
class AiProviderCatalog
{
    /**
     * 返回全部品牌定义（key 为品牌标识 brand）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function brands(): array
    {
        return [
            'openai' => [
                'label' => 'OpenAI',
                'icon' => 'openai',
                'protocol' => AiProviderProtocol::OpenAI->value,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key'),
                ],
                'default_models' => [
                    $this->model('gpt-5.5', 'GPT-5.5', 'llm', '旗舰通用模型，质量最高'),
                    $this->model('gpt-5.4-mini', 'GPT-5.4 Mini', 'llm', '性价比通用模型'),
                    $this->model('gpt-5.4-nano', 'GPT-5.4 Nano', 'llm', '最轻量，速度快、成本低'),
                    $this->model('text-embedding-3-large', 'Text Embedding 3 Large', 'embedding', '高精度向量模型'),
                    $this->model('text-embedding-3-small', 'Text Embedding 3 Small', 'embedding', '轻量向量模型'),
                ],
            ],
            'anthropic' => [
                'label' => 'Anthropic',
                'icon' => 'anthropic',
                'protocol' => AiProviderProtocol::Anthropic->value,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key'),
                ],
                'default_models' => [
                    $this->model('claude-opus-4-7', 'Claude Opus 4.7', 'llm', '旗舰款，复杂推理与长上下文强'),
                    $this->model('claude-sonnet-4-6', 'Claude Sonnet 4.6', 'llm', '均衡款，质量与速度兼顾'),
                    $this->model('claude-haiku-4-5', 'Claude Haiku 4.5', 'llm', '轻量快速款'),
                ],
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'icon' => 'google',
                'protocol' => AiProviderProtocol::Gemini->value,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key'),
                ],
                'default_models' => [
                    $this->model('gemini-3.5-flash', 'Gemini 3.5 Flash', 'llm', '快速通用模型，多模态'),
                ],
            ],
            'deepseek' => [
                'label' => 'DeepSeek',
                'icon' => 'deepseek',
                'protocol' => AiProviderProtocol::OpenAI->value,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key'),
                    $this->urlField('base_uri', 'Base URI', default: 'https://api.deepseek.com'),
                ],
                'default_models' => [
                    $this->model('deepseek-v4-pro', 'DeepSeek V4 Pro', 'llm', '旗舰款，推理能力强'),
                    $this->model('deepseek-v4-flash', 'DeepSeek V4 Flash', 'llm', '快速款，性价比高'),
                ],
            ],
            'openrouter' => [
                'label' => 'OpenRouter',
                'icon' => 'openrouter',
                'protocol' => AiProviderProtocol::OpenAI->value,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key'),
                    $this->urlField('base_uri', 'Base URI', default: 'https://openrouter.ai/api/v1'),
                ],
                'default_models' => [
                    $this->model('openai/gpt-5.5', 'GPT-5.5', 'llm', '经 OpenRouter 路由的 GPT-5.5'),
                    $this->model('anthropic/claude-opus-4-7', 'Claude Opus 4.7', 'llm', '经 OpenRouter 路由的 Claude Opus'),
                    $this->model('deepseek/deepseek-v4-flash', 'DeepSeek V4 Flash', 'llm', '经 OpenRouter 路由的 DeepSeek Flash'),
                    $this->model('openai/text-embedding-3-small', 'Text Embedding 3 Small', 'embedding', '经 OpenRouter 路由的轻量向量模型'),
                ],
            ],
            'qwen' => [
                'label' => 'Qwen',
                'icon' => 'qwen',
                'protocol' => AiProviderProtocol::OpenAI->value,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key'),
                    $this->urlField('base_uri', 'Base URI', default: 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
                ],
                'default_models' => [
                    $this->model('qwen3.6-max-preview', 'Qwen3.6 Max Preview', 'llm', '通义千问旗舰预览版'),
                    $this->model('qwen3.6-plus', 'Qwen3.6 Plus', 'llm', '通义千问增强款'),
                    $this->model('qwen3.6-flash', 'Qwen3.6 Flash', 'llm', '通义千问快速款'),
                    $this->model('qwen3-max', 'Qwen3 Max', 'llm', '通义千问大杯，能力全面'),
                    $this->model('qwen3-coder-plus', 'Qwen3 Coder Plus', 'llm', '通义千问代码增强款'),
                    $this->model('text-embedding-v4', 'Text Embedding V4', 'embedding', '通义向量模型'),
                ],
            ],
            'ark' => [
                'label' => 'Volcengine Ark',
                'icon' => 'ark',
                'protocol' => AiProviderProtocol::OpenAI->value,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key'),
                    $this->urlField('base_uri', 'Base URI', default: 'https://ark.cn-beijing.volces.com/api/v3'),
                ],
                'default_models' => [],
            ],
            'azure-openai' => [
                'label' => 'Azure OpenAI',
                'icon' => 'azure',
                'protocol' => AiProviderProtocol::OpenAI->value,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key'),
                    $this->urlField('base_uri', 'Base URI', placeholder: 'https://RESOURCE.openai.azure.com/openai/v1', editable: true),
                ],
                'default_models' => [],
            ],
            'ollama' => [
                'label' => 'Ollama',
                'icon' => 'ollama',
                'protocol' => AiProviderProtocol::OpenAI->value,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key', required: false),
                    $this->urlField('base_uri', 'Base URI', default: 'http://localhost:11434/v1', editable: true),
                ],
                'default_models' => [],
            ],
            'custom-openai' => [
                'label' => '自定义（OpenAI 兼容）',
                'icon' => 'openai',
                'protocol' => AiProviderProtocol::OpenAI->value,
                'is_custom' => true,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key'),
                    $this->urlField('base_uri', 'Base URI'),
                ],
                'default_models' => [],
            ],
            'custom-anthropic' => [
                'label' => '自定义（Anthropic 兼容）',
                'icon' => 'anthropic',
                'protocol' => AiProviderProtocol::Anthropic->value,
                'is_custom' => true,
                'credential_fields' => [
                    $this->passwordField('key', 'API Key'),
                    $this->urlField('base_uri', 'Base URI'),
                ],
                'default_models' => [],
            ],
        ];
    }

    /**
     * 取指定品牌定义，不存在则抛异常。
     *
     * @return array<string, mixed>
     */
    public function brand(string $brand): array
    {
        $definition = $this->brands()[$brand] ?? null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unsupported AI provider brand [{$brand}].");
        }

        return $definition;
    }

    /**
     * 判断品牌是否存在。
     */
    public function hasBrand(string $brand): bool
    {
        return array_key_exists($brand, $this->brands());
    }

    /**
     * 返回前端品牌目录（新增供应商对话框消费），含每个品牌的图标与凭据字段。
     *
     * @return array<int, array<string, mixed>>
     */
    public function brandOptions(): array
    {
        $options = [];

        foreach ($this->brands() as $brand => $definition) {
            $options[] = [
                'brand' => $brand,
                'label' => (string) $definition['label'],
                'icon' => is_string($definition['icon'] ?? null) ? $definition['icon'] : null,
                'is_custom' => (bool) ($definition['is_custom'] ?? false),
                'credential_fields' => $definition['credential_fields'],
            ];
        }

        return $options;
    }

    /**
     * 取指定品牌的凭据字段定义。
     *
     * @return array<int, array<string, mixed>>
     */
    public function credentialFieldsForBrand(string $brand): array
    {
        return $this->brand($brand)['credential_fields'];
    }

    /**
     * 取指定品牌的默认模型列表。
     *
     * @return array<int, array<string, mixed>>
     */
    public function defaultModelsForBrand(string $brand): array
    {
        return $this->brand($brand)['default_models'];
    }

    /**
     * 取指定品牌的底层协议。
     */
    public function protocolForBrand(string $brand): string
    {
        return (string) $this->brand($brand)['protocol'];
    }

    /**
     * 取指定品牌的展示名称。
     */
    public function labelForBrand(string $brand): string
    {
        return (string) $this->brand($brand)['label'];
    }

    /**
     * 取指定品牌的图标标识。
     */
    public function iconForBrand(string $brand): ?string
    {
        $icon = $this->brand($brand)['icon'];

        return is_string($icon) ? $icon : null;
    }

    /**
     * 判断品牌是否为自定义入口（用户自填名称与端点）。
     */
    public function isCustomBrand(string $brand): bool
    {
        return (bool) ($this->brand($brand)['is_custom'] ?? false);
    }

    /**
     * 生成指定品牌的默认凭据配置（取凭据字段里声明的 default 值，如预设 base_uri）。
     *
     * @return array<string, mixed>
     */
    public function defaultConfigurationForBrand(string $brand): array
    {
        $configuration = [];

        foreach ($this->credentialFieldsForBrand($brand) as $field) {
            if (array_key_exists('default', $field) && $field['default'] !== null) {
                $configuration[$field['field']] = $field['default'];
            }
        }

        return $configuration;
    }

    /**
     * 拼装内置模型配置；description 为简短说明，供「预设模型」选择弹窗展示。
     *
     * @return array<string, mixed>
     */
    private function model(
        string $modelId,
        string $name,
        string $type,
        string $description = '',
    ): array {
        return [
            'model_id' => $modelId,
            'name' => $name,
            'type' => $type,
            'description' => $description,
        ];
    }

    /**
     * 声明一个密钥字段。
     *
     * @return array<string, mixed>
     */
    private function passwordField(string $field, string $label, bool $required = true, ?string $default = null): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'type' => 'password',
            'required' => $required,
            'secret' => true,
            'default' => $default,
        ];
    }

    /**
     * 声明一个 URL 字段。
     *
     * @return array<string, mixed>
     */
    private function urlField(string $field, string $label, bool $required = true, ?string $default = null, ?string $placeholder = null, bool $editable = false): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'type' => 'url',
            'required' => $required,
            'default' => $default,
            'placeholder' => $placeholder,
            // 端点因部署而异（Azure 按资源、Ollama 按主机），即便是预设品牌也允许手动修改 base_uri
            'editable' => $editable,
        ];
    }
}
