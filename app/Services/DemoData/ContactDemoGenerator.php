<?php

namespace App\Services\DemoData;

use App\Enums\ContactSource;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Workspace;
use App\Services\Contact\ContactAiContext;

/**
 * 生成联系人演示数据。
 */
class ContactDemoGenerator
{
    /**
     * 按数量生成一批联系人演示数据。
     */
    public function generate(Workspace $workspace, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        $anonymousVisitorCount = (int) floor($count * 0.4);
        $knownVisitorCount = (int) floor($count * 0.3);
        $contactCount = $count - $anonymousVisitorCount - $knownVisitorCount;

        $this->createAnonymousVisitors($workspace, $anonymousVisitorCount);
        $this->createKnownVisitors($workspace, $knownVisitorCount);
        $this->createContacts($workspace, $contactCount);

        return $count;
    }

    /**
     * 生成固定比例的联系人演示数据。
     */
    public function generatePreset(Workspace $workspace): int
    {
        $contactCount = 20;
        $anonymousVisitorCount = 15;
        $knownVisitorCount = 10;
        $multiIdentityCount = 5;

        $this->createContacts($workspace, $contactCount);
        $this->createAnonymousVisitors($workspace, $anonymousVisitorCount);
        $this->createKnownVisitors($workspace, $knownVisitorCount);
        $this->createMultiIdentityContacts($workspace, $multiIdentityCount);

        return $contactCount + $anonymousVisitorCount + $knownVisitorCount + $multiIdentityCount;
    }

    /**
     * 生成带多种身份的正式联系人。
     */
    private function createContacts(Workspace $workspace, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        Contact::factory()
            ->count($count)
            ->contact()
            ->fromSource(fake()->randomElement(ContactSource::cases()))
            ->create()
            ->each(function (Contact $contact) {
                if (fake()->boolean(60)) {
                    $contact->updateQuietly([
                        'ai_context' => ContactAiContext::normalize([
                            'preferences' => fake()->sentence(),
                            'past_issues' => fake()->sentence(),
                            'sentiment' => fake()->randomElement(['positive', 'neutral', 'negative']),
                        ]),
                    ]);
                }

                ContactIdentity::factory()->email()->create([
                    'contact_id' => $contact->id,
                ]);

                ContactIdentity::factory()->session()->create([
                    'contact_id' => $contact->id,
                ]);

                if (fake()->boolean(50)) {
                    ContactIdentity::factory()->phone()->create([
                        'contact_id' => $contact->id,
                    ]);
                }

                if (fake()->boolean(20)) {
                    ContactIdentity::factory()->externalId()->create([
                        'contact_id' => $contact->id,
                    ]);
                }

                $contact->syncPrimaryFields();
            });
    }

    /**
     * 生成只有会话身份的匿名访客。
     */
    private function createAnonymousVisitors(Workspace $workspace, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        Contact::factory()
            ->count($count)
            ->anonymous()
            ->fromSource(ContactSource::Web)
            ->create()
            ->each(function (Contact $contact) {
                ContactIdentity::factory()->session()->create([
                    'contact_id' => $contact->id,
                ]);

                $contact->syncPrimaryFields();
            });
    }

    /**
     * 生成带邮箱和会话身份的访客。
     */
    private function createKnownVisitors(Workspace $workspace, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        Contact::factory()
            ->count($count)
            ->visitor()
            ->create()
            ->each(function (Contact $contact) {
                ContactIdentity::factory()->email()->create([
                    'contact_id' => $contact->id,
                ]);

                ContactIdentity::factory()->session()->create([
                    'contact_id' => $contact->id,
                ]);

                $contact->syncPrimaryFields();
            });
    }

    /**
     * 生成身份更完整的联系人样本。
     */
    private function createMultiIdentityContacts(Workspace $workspace, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        Contact::factory()
            ->count($count)
            ->contact()
            ->create()
            ->each(function (Contact $contact) {
                ContactIdentity::factory()->email()->create([
                    'contact_id' => $contact->id,
                ]);

                ContactIdentity::factory()->phone()->create([
                    'contact_id' => $contact->id,
                ]);

                ContactIdentity::factory()->session()->create([
                    'contact_id' => $contact->id,
                ]);

                ContactIdentity::factory()->externalId()->create([
                    'contact_id' => $contact->id,
                ]);

                $contact->updateQuietly([
                    'ai_context' => ContactAiContext::normalize([
                        'preferences' => fake()->sentence(),
                        'past_issues' => fake()->sentence(),
                        'sentiment' => fake()->randomElement(['positive', 'neutral', 'negative']),
                    ]),
                ]);

                $contact->syncPrimaryFields();
            });
    }
}
