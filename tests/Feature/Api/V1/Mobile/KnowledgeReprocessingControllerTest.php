<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Jobs\Fetch\FetchSingleUrl;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Knowledge\KnowledgeReprocessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KnowledgeReprocessingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    private int $knowledgeObjectSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function reprocess_requires_write_ability(): void
    {
        $event = $this->createFetchEvent();
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson("/api/v1/mobile/knowledge/events/{$event->id}/reprocess")
            ->assertStatus(403);
    }

    #[Test]
    public function reprocess_returns_404_for_another_users_event(): void
    {
        $otherUser = User::factory()->create();
        $event = $this->createFetchEvent($otherUser);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/knowledge/events/{$event->id}/reprocess")
            ->assertStatus(404);
    }

    #[Test]
    public function reprocess_rejects_unsupported_events(): void
    {
        $integration = $this->createIntegration('monzo');
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
        ]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/knowledge/events/{$event->id}/reprocess", [], $this->ifMatch($event))
            ->assertStatus(422);
    }

    #[Test]
    public function refetch_queues_force_fetch_for_fetch_events(): void
    {
        $event = $this->createFetchEvent();
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/knowledge/events/{$event->id}/reprocess", [
            'mode' => 'refetch',
        ], $this->ifMatch($event))
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('service', 'fetch');

        Queue::assertPushed(FetchSingleUrl::class, fn (FetchSingleUrl $job) => $job->forceRefresh === true
            && $job->webpageObjectId === $event->target_id);
    }

    #[Test]
    public function summary_only_queues_fetch_summary_generation_from_existing_content(): void
    {
        $event = $this->createFetchEvent(webpageContent: 'Existing extracted markdown.');
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/knowledge/events/{$event->id}/reprocess", [
            'mode' => 'summary_only',
        ], $this->ifMatch($event))->assertAccepted();

        Queue::assertPushed(ProcessTaskPipelineJob::class, fn (ProcessTaskPipelineJob $job) => $job->model->is($event)
            && $job->trigger === 'manual'
            && $job->taskFilter === ['fetch_generate_summaries']);
    }

    #[Test]
    public function newsletter_auto_queues_extraction_from_raw_html(): void
    {
        $event = $this->createNewsletterEvent(rawHtml: '<main>Newsletter body</main>');
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/knowledge/events/{$event->id}/reprocess", [], $this->ifMatch($event))
            ->assertAccepted()
            ->assertJsonPath('service', 'newsletter');

        Queue::assertPushed(ProcessTaskPipelineJob::class, fn (ProcessTaskPipelineJob $job) => $job->model->is($event)
            && $job->trigger === 'manual'
            && $job->taskFilter === ['newsletter_extract_content', 'newsletter_generate_summaries']);
    }

    #[Test]
    public function newsletter_auto_queues_pipeline_when_raw_html_and_content_are_missing(): void
    {
        $event = $this->createNewsletterEvent(rawHtml: null, publicationContent: null);
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/knowledge/events/{$event->id}/reprocess", [], $this->ifMatch($event))
            ->assertAccepted();

        Queue::assertPushed(ProcessTaskPipelineJob::class, fn (ProcessTaskPipelineJob $job) => $job->model->is($event)
            && $job->trigger === 'manual'
            && $job->taskFilter === ['newsletter_extract_content', 'newsletter_generate_summaries']);
    }

    #[Test]
    public function missing_tldr_detector_finds_fetch_and_newsletter_events_without_usable_tldr(): void
    {
        $service = app(KnowledgeReprocessingService::class);

        $fetchMissing = $this->createFetchEvent();
        $newsletterMissing = $this->createNewsletterEvent();
        $fetchComplete = $this->createFetchEvent();
        $newsletterEmpty = $this->createNewsletterEvent();

        Block::factory()->create([
            'event_id' => $fetchComplete->id,
            'block_type' => 'fetch_tldr',
            'metadata' => ['content' => 'Done.'],
        ]);

        Block::factory()->create([
            'event_id' => $newsletterEmpty->id,
            'block_type' => 'newsletter_tldr',
            'metadata' => ['content' => ''],
        ]);

        $ids = $service->missingTldrEvents()->pluck('id');

        $this->assertTrue($ids->contains($fetchMissing->id));
        $this->assertTrue($ids->contains($newsletterMissing->id));
        $this->assertTrue($ids->contains($newsletterEmpty->id));
        $this->assertFalse($ids->contains($fetchComplete->id));
    }

    private function createIntegration(string $service, ?User $user = null): Integration
    {
        $user ??= $this->user;
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => $service,
        ]);

        return Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => $service,
        ]);
    }

    private function createFetchEvent(?User $user = null, ?string $webpageContent = null): Event
    {
        $user ??= $this->user;
        $integration = $this->createIntegration('fetch', $user);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Example Article '.$this->nextKnowledgeObjectSequence(),
            'url' => 'https://example.com/article',
            'content' => $webpageContent,
        ]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'fetch',
            'domain' => 'knowledge',
            'action' => 'fetched',
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'event_metadata' => [],
        ]);
    }

    private function createNewsletterEvent(?string $rawHtml = '<main>Body</main>', ?string $publicationContent = 'Extracted newsletter.'): Event
    {
        $integration = $this->createIntegration('newsletter');
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'publication',
            'type' => 'newsletter_publication',
            'title' => 'Example Newsletter '.$this->nextKnowledgeObjectSequence(),
            'content' => $publicationContent,
        ]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'newsletter',
            'domain' => 'knowledge',
            'action' => 'received_post',
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'event_metadata' => array_filter([
                'email_subject' => 'Issue 1',
                'raw_html' => $rawHtml,
            ], fn ($value) => $value !== null),
        ]);
    }

    private function nextKnowledgeObjectSequence(): int
    {
        return ++$this->knowledgeObjectSequence;
    }

    /** @return array{If-Match: string} */
    private function ifMatch(Event $event): array
    {
        return ['If-Match' => $this->getJson("/api/v1/mobile/events/{$event->id}")->headers->get('ETag')];
    }
}
