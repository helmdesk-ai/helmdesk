<?php

namespace Database\Factories;

use App\Enums\ConversationEventType;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationEvent>
 */
class ConversationEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'conversation_id' => fn (array $attributes) => Conversation::factory()->create([
                'workspace_id' => $attributes['workspace_id'],
            ])->id,
            'actor_user_id' => null,
            'type' => ConversationEventType::Created,
            'payload' => ['source' => 'manual'],
            'created_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ];
    }

    public function forConversation(Conversation $conversation): static
    {
        return $this->state([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
        ]);
    }

    public function handoffRequested(): static
    {
        return $this->state([
            'actor_user_id' => null,
            'type' => ConversationEventType::HandoffRequested,
            'payload' => ['reason' => 'tool_failure'],
        ]);
    }
}
