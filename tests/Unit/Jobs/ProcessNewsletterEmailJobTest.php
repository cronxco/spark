<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Newsletter\ProcessNewsletterEmailJob;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessNewsletterEmailJobTest extends TestCase
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
    public function it_can_be_instantiated()
    {
        $job = new ProcessNewsletterEmailJob(
            $this->integration,
            'newsletters/test-email.eml'
        );

        $this->assertInstanceOf(ProcessNewsletterEmailJob::class, $job);
    }

    /** @test */
    public function it_has_correct_timeout()
    {
        $job = new ProcessNewsletterEmailJob(
            $this->integration,
            'newsletters/test-email.eml'
        );

        $this->assertEquals(300, $job->timeout);
    }

    /** @test */
    public function it_has_correct_retry_settings()
    {
        $job = new ProcessNewsletterEmailJob(
            $this->integration,
            'newsletters/test-email.eml'
        );

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 900], $job->backoff);
    }

    /** @test */
    public function it_generates_unique_id_based_on_integration_and_s3_key()
    {
        $s3Key = 'newsletters/test-email-123.eml';
        $job = new ProcessNewsletterEmailJob($this->integration, $s3Key);

        $uniqueId = $job->uniqueId();

        $this->assertStringStartsWith('process_newsletter_email_', $uniqueId);
        $this->assertStringContainsString($this->integration->id, $uniqueId);
        $this->assertStringContainsString(md5($s3Key), $uniqueId);
    }

    /** @test */
    public function it_generates_different_unique_ids_for_different_s3_keys()
    {
        $job1 = new ProcessNewsletterEmailJob(
            $this->integration,
            'newsletters/email-1.eml'
        );

        $job2 = new ProcessNewsletterEmailJob(
            $this->integration,
            'newsletters/email-2.eml'
        );

        $this->assertNotEquals($job1->uniqueId(), $job2->uniqueId());
    }

    /** @test */
    public function it_generates_unique_id_based_on_raw_content_when_no_s3_key()
    {
        $rawContent = 'Raw email content here';
        $job = new ProcessNewsletterEmailJob(
            $this->integration,
            null,
            $rawContent
        );

        $uniqueId = $job->uniqueId();

        $this->assertStringStartsWith('process_newsletter_email_', $uniqueId);
        $this->assertStringContainsString($this->integration->id, $uniqueId);
        $this->assertStringContainsString(md5($rawContent), $uniqueId);
    }

    /** @test */
    public function it_can_be_dispatched_with_s3_key()
    {
        $job = ProcessNewsletterEmailJob::dispatch(
            $this->integration,
            'newsletters/test-email.eml'
        );

        $this->assertTrue(true); // If we get here, dispatch worked
    }

    /** @test */
    public function it_can_be_dispatched_with_raw_content()
    {
        $job = ProcessNewsletterEmailJob::dispatch(
            $this->integration,
            null,
            'Raw email content'
        );

        $this->assertTrue(true); // If we get here, dispatch worked
    }
}
