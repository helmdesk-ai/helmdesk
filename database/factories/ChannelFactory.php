<?php

namespace Database\Factories;

use App\Data\Channel\Telegram\ChannelTelegramSettingsData;
use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Enums\ChannelType;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    /**
     * 定义模型的默认状态。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ChannelType::Web,
            'name' => fake()->company().' 官网',
            'settings' => ChannelWebSettingsData::defaults(),
        ];
    }

    /**
     * 生成 Telegram Bot 渠道：携带加密的 bot_token 与入站校验密钥。
     */
    public function telegram(): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Telegram,
            'name' => fake()->company().' Bot',
            'telegram_bot_token' => fake()->numerify('##########').':'.Str::random(35),
            'settings' => ChannelTelegramSettingsData::defaults([
                'webhook_secret' => Str::random(40),
                'bot_username' => fake()->userName().'_bot',
                'bot_id' => fake()->numberBetween(100000000, 999999999),
            ]),
        ]);
    }
}
