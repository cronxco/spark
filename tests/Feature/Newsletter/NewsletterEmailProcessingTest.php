<?php

namespace Tests\Feature\Newsletter;

use App\Jobs\Data\Newsletter\ProcessNewsletterEmailJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NewsletterEmailProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'newsletter',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'newsletter',
            'instance_type' => 'newsletters',
        ]);
    }

    /** @test */
    public function it_creates_publication_event_object_from_newsletter_email()
    {
        Queue::fake();

        $rawEmail = $this->getSampleNewsletterEmail();

        $job = new ProcessNewsletterEmailJob(
            $this->integration,
            null,
            $rawEmail
        );

        // Manually call handle to bypass queue
        try {
            $job->handle();
        } catch (Exception $e) {
            // Expected to fail at extraction job dispatch (OpenAI not available in tests)
            // But publication and event should still be created
        }

        // Check publication EventObject was created
        $publication = EventObject::where('user_id', $this->user->id)
            ->where('concept', 'publication')
            ->where('type', 'newsletter_publication')
            ->first();

        $this->assertNotNull($publication);
        $this->assertEquals('Morning Brew', $publication->title);
        $this->assertArrayHasKey('sender_email', $publication->metadata);
        $this->assertArrayHasKey('sender_domain', $publication->metadata);
        $this->assertEquals('crew@morningbrew.com', $publication->metadata['sender_email']);
        $this->assertEquals('morningbrew.com', $publication->metadata['sender_domain']);
    }

    /** @test */
    public function it_creates_newsletter_user_actor()
    {
        Queue::fake();

        $rawEmail = $this->getSampleNewsletterEmail();

        $job = new ProcessNewsletterEmailJob(
            $this->integration,
            null,
            $rawEmail
        );

        try {
            $job->handle();
        } catch (Exception $e) {
            // Expected to fail at extraction job dispatch
        }

        // Check "Me" actor was created
        $actor = EventObject::where('user_id', $this->user->id)
            ->where('concept', 'user')
            ->where('type', 'newsletter_user')
            ->where('title', 'Me')
            ->first();

        $this->assertNotNull($actor);
    }

    /** @test */
    public function it_creates_received_post_event()
    {
        Queue::fake();

        $rawEmail = $this->getSampleNewsletterEmail();

        $job = new ProcessNewsletterEmailJob(
            $this->integration,
            null,
            $rawEmail
        );

        try {
            $job->handle();
        } catch (Exception $e) {
            // Expected to fail at extraction job dispatch
        }

        // Check event was created
        $event = Event::where('integration_id', $this->integration->id)
            ->where('service', 'newsletter')
            ->where('action', 'received_post')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals('knowledge', $event->domain);
        $this->assertArrayHasKey('email_subject', $event->event_metadata);
        $this->assertEquals('Morning Brew: Daily business news - Dec 21', $event->event_metadata['email_subject']);
    }

    /** @test */
    public function it_dispatches_process_newsletter_email_job()
    {
        Queue::fake();

        $rawEmail = $this->getSampleNewsletterEmail();

        ProcessNewsletterEmailJob::dispatch(
            $this->integration,
            null,
            $rawEmail
        );

        Queue::assertPushed(ProcessNewsletterEmailJob::class);
    }

    /** @test */
    public function it_handles_different_sender_formats()
    {
        Queue::fake();

        // Test with quoted name format
        $rawEmail = $this->getSampleNewsletterEmail(
            '"The Browser" <hello@thebrowser.com>'
        );

        $job = new ProcessNewsletterEmailJob(
            $this->integration,
            null,
            $rawEmail
        );

        try {
            $job->handle();
        } catch (Exception $e) {
            // Expected
        }

        $publication = EventObject::where('user_id', $this->user->id)
            ->where('concept', 'publication')
            ->where('type', 'newsletter_publication')
            ->first();

        $this->assertEquals('The Browser', $publication->title);
    }

    /** @test */
    public function it_groups_newsletters_from_same_publication()
    {
        Queue::fake();

        // Process first newsletter
        $email1 = $this->getSampleNewsletterEmail();
        $job1 = new ProcessNewsletterEmailJob($this->integration, null, $email1);

        try {
            $job1->handle();
        } catch (Exception $e) {
            // Expected
        }

        // Process second newsletter from same sender
        $email2 = $this->getSampleNewsletterEmail('Morning Brew <crew@morningbrew.com>', 'Different subject');
        $job2 = new ProcessNewsletterEmailJob($this->integration, null, $email2);

        try {
            $job2->handle();
        } catch (Exception $e) {
            // Expected
        }

        // Should only have ONE publication EventObject
        $publicationCount = EventObject::where('user_id', $this->user->id)
            ->where('concept', 'publication')
            ->where('type', 'newsletter_publication')
            ->count();

        $this->assertEquals(1, $publicationCount);

        // But TWO events
        $eventCount = Event::where('integration_id', $this->integration->id)
            ->where('service', 'newsletter')
            ->where('action', 'received_post')
            ->count();

        $this->assertEquals(2, $eventCount);
    }

    /**
     * Generate a sample newsletter email in MIME format
     */
    private function getSampleNewsletterEmail(
        string $from = 'Morning Brew <crew@morningbrew.com>',
        string $subject = 'Morning Brew: Daily business news - Dec 21'
    ): string {
        $messageId = '<'.uniqid().'@morningbrew.com>';
        $date = now()->toRfc2822String();

        return <<<EMAIL
From: {$from}
To: news@spark.cronx.co
Subject: {$subject}
Date: {$date}
Message-ID: {$messageId}
Content-Type: text/html; charset=utf-8

<!DOCTYPE html>
<html>
<head><title>Morning Brew</title></head>
<body>
<div class="header">
    <img src="logo.png" alt="Morning Brew">
</div>
<div class="content">
    <h1>Good morning!</h1>
    <p>Here's what you need to know in business today.</p>

    <h2>Top Story</h2>
    <p>The stock market reached new highs yesterday as investors...</p>

    <h2>Tech News</h2>
    <p>Apple announced a new product line that will...</p>

    <h2>Economy</h2>
    <p>The Federal Reserve indicated that interest rates...</p>
</div>
<div class="footer">
    <p><a href="https://www.morningbrew.com/unsubscribe">Unsubscribe</a></p>
    <p>Morning Brew, 123 Main St, New York, NY 10001</p>
</div>
</body>
</html>
EMAIL;
    }
}
