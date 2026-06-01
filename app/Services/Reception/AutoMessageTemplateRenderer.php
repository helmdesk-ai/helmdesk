<?php

namespace App\Services\Reception;

use Illuminate\Validation\ValidationException;

/**
 * 渲染接待自动回复模板。
 *
 * 模板变量固定为明确白名单。
 */
class AutoMessageTemplateRenderer
{
    /**
     * @var list<string>
     */
    private const ALLOWED_VARIABLES = [
        'display_name',
        'teammate_name',
    ];

    /**
     * 渲染模板，并在出现未知变量时显式报错。
     *
     * @param  array<string, string|null>  $variables
     */
    public function render(string $template, array $variables): string
    {
        $unknown = $this->unknownVariables($template);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'message' => __('reception.messages.auto_message_unknown_variable', [
                    'variable' => $unknown[0],
                ]),
            ]);
        }

        $content = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $matches) use ($variables): string {
            $key = (string) $matches[1];

            return (string) ($variables[$key] ?? '');
        }, $template);

        return trim((string) $content);
    }

    /**
     * 返回模板中的未知变量名。
     *
     * @return list<string>
     */
    private function unknownVariables(string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $template, $matches);
        $variables = array_values(array_unique($matches[1] ?? []));

        return array_values(array_filter(
            $variables,
            static fn (string $variable): bool => ! in_array($variable, self::ALLOWED_VARIABLES, true),
        ));
    }
}
