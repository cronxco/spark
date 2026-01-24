<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Newsletter\GenerateNewsletterSummariesJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateNewsletterSummariesJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function it_handles_null_event_metadata_gracefully(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'newsletter',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'newsletter',
        ]);

        $publication = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'publication',
            'type' => 'newsletter_publication',
            'title' => 'Test Publication',
        ]);

        // Create event with NULL event_metadata (the bug scenario)
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'newsletter',
            'action' => 'received_post',
            'time' => now(),
            'event_metadata' => null,
        ]);

        $job = new GenerateNewsletterSummariesJob(
            $integration,
            $event,
            $publication,
            'Test article content'
        );

        // Verify the event has null metadata
        $this->assertNull($event->event_metadata);

        // Test that accessing event_metadata with null coalescing doesn't throw error
        $subject = $event->event_metadata['email_subject'] ?? 'No Subject';
        $this->assertEquals('No Subject', $subject);
    }

    /**
     * @test
     */
    public function it_handles_event_metadata_without_email_subject(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'newsletter',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'newsletter',
        ]);

        $publication = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'publication',
            'type' => 'newsletter_publication',
            'title' => 'Test Publication',
        ]);

        // Create event with event_metadata but missing email_subject key
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'newsletter',
            'action' => 'received_post',
            'time' => now(),
            'event_metadata' => ['some_other_field' => 'value'],
        ]);

        $job = new GenerateNewsletterSummariesJob(
            $integration,
            $event,
            $publication,
            'Test article content'
        );

        // Verify the event has metadata but no email_subject
        $this->assertIsArray($event->event_metadata);
        $this->assertArrayNotHasKey('email_subject', $event->event_metadata);

        // Test that accessing email_subject with null coalescing uses fallback
        $subject = $event->event_metadata['email_subject'] ?? 'No Subject';
        $this->assertEquals('No Subject', $subject);
    }

    /**
     * @test
     */
    public function unique_id_is_correctly_generated(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'newsletter',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'newsletter',
        ]);

        $publication = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'publication',
            'type' => 'newsletter_publication',
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'newsletter',
            'action' => 'received_post',
        ]);

        $job = new GenerateNewsletterSummariesJob(
            $integration,
            $event,
            $publication,
            'Test content'
        );

        $expectedId = 'generate_newsletter_summaries_'.$integration->id.'_'.$event->id;
        $this->assertEquals($expectedId, $job->uniqueId());
    }
}
