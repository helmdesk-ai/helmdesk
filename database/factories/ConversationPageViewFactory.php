<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationPageView;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationPageView>
 */
class ConversationPageViewFactory extends Factory
{
    /**
     * 定义模型的默认状态。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'conversation_id' => fn (array $attributes) => Conversation::factory()->create([
                'workspace_id' => $attributes['workspace_id'],
            ])->id,
            'contact_id' => null,
            'url' => fake()->url(),
            'title' => fake()->sentence(3),
            'referrer' => fake()->url(),
            'viewed_at' => now(),
        ];
    }

    /**
     * 绑定到指定会话，并对齐 workspace。
     */
    public function forConversation(Conversation $conversation): static
    {
        return $this->state([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
        ]);
    }
}
