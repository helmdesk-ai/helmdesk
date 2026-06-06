<?php

namespace Database\Factories;

use App\Enums\ConversationInboxStatus;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\ReceptionLanguage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        $createdAt = fake()->dateTimeBetween('-14 days', 'now');

        return [
            'contact_id' => null,
            'assigned_user_id' => null,
            'channel_id' => null,
            'entry_mode' => null,
            'visitor_locale' => ReceptionLanguage::ChineseSimplified->value,
            'source' => ConversationSource::Channel,
            'status' => ConversationStatus::Open,
            'inbox_status' => fake()->randomElement(ConversationInboxStatus::cases()),
            'waiting_for_visitor_reply' => false,
            'subject' => fake()->sentence(),
            'summary' => fake()->optional()->paragraph(),
            'ai_context' => ['language' => fake()->randomElement(['zh-CN', 'en-US'])],
            'last_message_preview' => fake()->sentence(),
            'last_message_at' => $createdAt,
            'unread_visitor_message_count' => 0,
            'unread_agent_message_count' => 0,
            'next_seq_no' => 0,
            'closed_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    public function unassigned(): static
    {
        return $this->state([
            'assigned_user_id' => null,
        ]);
    }

    public function withoutContact(): static
    {
        return $this->state([
            'contact_id' => null,
        ]);
    }

    public function forContact(Contact $contact): static
    {
        return $this->state([
            'contact_id' => $contact->id,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state([
            'assigned_user_id' => $user->id,
        ]);
    }

    public function closed(): static
    {
        return $this->state(function (): array {
            $closedAt = fake()->dateTimeBetween('-7 days', 'now');

            return [
                'status' => ConversationStatus::Closed,
                'inbox_status' => ConversationInboxStatus::TeammateHandling,
                'waiting_for_visitor_reply' => false,
                'closed_at' => $closedAt,
                'last_message_at' => $closedAt,
            ];
        });
    }

    public function waitingForVisitorReply(): static
    {
        return $this->state([
            'waiting_for_visitor_reply' => true,
        ]);
    }

    /**
     * 串起 plan → version → channel(reception_plan_id) → conversation(reception_plan_version_id) 链路：
     * 渠道绑定方案自动跟随最新版，会话仍锁定到对应版本，便于测试新会话已锁定接待方案版本的语义。
     */
    public function withReceptionPlanVersion(): static
    {
        $plan = ReceptionPlan::factory()->create();
        $planVersion = ReceptionPlanVersion::factory()->for($plan, 'plan')->create();
        $channel = Channel::factory()->create([
            'reception_plan_id' => $plan->id,
        ]);

        return $this->state([
            'channel_id' => $channel->id,
            'reception_plan_version_id' => $planVersion->id,
        ]);
    }
}
