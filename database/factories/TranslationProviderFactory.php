<?php

namespace Database\Factories;

use App\Enums\TranslationProviderType;
use App\Models\TranslationProvider;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TranslationProvider>
 */
class TranslationProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'slug' => 'google-translate-'.Str::lower(Str::random(6)),
            'name' => 'Google Translate',
            'protocol' => TranslationProviderType::GoogleTranslate,
            'icon' => null,
            'credentials' => ['api_key' => 'test-api-key'],
            'credential_fields' => [
                ['field' => 'api_key', 'label' => 'API Key', 'required' => true, 'secret' => true],
            ],
            'options' => null,
            'is_builtin' => true,
            'sort_order' => 0,
        ];
    }
}
