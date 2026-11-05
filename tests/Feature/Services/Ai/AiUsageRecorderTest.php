<?php

namespace Tests\Feature\Services\Ai;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\Ai\AiTokenUsage;
use App\Services\Ai\AiUsageContext;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\EmbeddingClient;
use App\Services\Ai\Exceptions\AiDailyTokenCapExceeded;
use App\Services\DaySummaryService;
use App\Services\Mobile\EventFeed;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiUsageRecorderTest extends TestCase
{
    #[Test]
    public function it_aggregates_by_user_local_day_and_model_with_idempotent_completion(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $recorder = app(AiUsageRecorder::class);
        $context = new AiUsageContext($user, 'chat', 'newsletter', skill: 'flint-news-roundup');

        $first = $recorder->reserve($context, 'gpt-5.1', 100, 'client-one');
        $recorder->complete($first, new AiTokenUsage(30, 20, 4, 6), 'client-one');
        $recorder->complete($first, new AiTokenUsage(30, 20, 4, 6), 'client-one');

        $second = $recorder->reserve($context, 'gpt-5.1', 100, 'client-two');
        $recorder->complete($second, new AiTokenUsage(10, 5), 'client-two');

        $otherModel = $recorder->reserve($context, 'gpt-5-mini', 25, 'client-three');
        $recorder->complete($otherModel, new AiTokenUsage(7, 3), 'client-three');

        $events = Event::query()->where('service', 'openai')->orderBy('source_id')->get();
        $this->assertCount(2, $events);

        $event = $events->firstWhere('event_metadata.model', 'gpt-5.1');
        $this->assertSame(65, (int) $event->value);
        $this->assertSame(2, $event->event_metadata['request_count']);
        $this->assertSame(40, $event->event_metadata['input_tokens']);
        $this->assertSame(25, $event->event_metadata['output_tokens']);
        $this->assertSame(2, $event->event_metadata['operations']['chat']['request_count']);
        $this->assertSame(2, $event->event_metadata['skills']['flint-news-roundup']['request_count']);
        $this->assertCount(2, $event->event_metadata['recorded_requests']);
    }

    #[Test]
    public function reservations_enforce_the_cap_and_failure_is_idempotent(): void
    {
        config(['services.openai.daily_token_cap' => 100]);
        $user = User::factory()->create();
        $recorder = app(AiUsageRecorder::class);
        $context = new AiUsageContext($user, 'embedding');

        $reservation = $recorder->reserve($context, 'text-embedding-3-small', 70);

        $this->expectException(AiDailyTokenCapExceeded::class);
        try {
            $recorder->reserve($context, 'text-embedding-3-small', 31);
        } finally {
            $recorder->fail($reservation);
            $recorder->fail($reservation);

            $event = Event::query()->where('service', 'openai')->firstOrFail();
            $this->assertSame(1, $event->event_metadata['failure_count']);
            $this->assertSame([], $event->event_metadata['active_reservations']);
        }
    }

    #[Test]
    public function a_zero_cap_is_disabled_and_local_dates_follow_the_user_timezone(): void
    {
        config(['services.openai.daily_token_cap' => 0]);
        Carbon::setTestNow(Carbon::parse('2026-08-31 23:30:00 UTC'));

        try {
            $user = User::factory()->create(['settings' => ['timezone' => 'Asia/Tokyo']]);
            $reservation = app(AiUsageRecorder::class)->reserve(
                new AiUsageContext($user, 'agent'),
                'gpt-5.1',
                10_000_000,
            );

            $this->assertSame('2026-09-01', $reservation->localDate);
            $this->assertStringContainsString('ai_usage:2026-09-01:', Event::findOrFail($reservation->eventId)->source_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function an_unbounded_request_is_rejected_when_no_daily_allowance_remains(): void
    {
        config(['services.openai.daily_token_cap' => 100]);
        $user = User::factory()->create();
        $context = new AiUsageContext($user, 'agent');
        $recorder = app(AiUsageRecorder::class);
        $reservation = $recorder->reserve($context, 'gpt-5.1', 100);
        $recorder->complete($reservation, new AiTokenUsage(75, 25));

        $this->expectException(AiDailyTokenCapExceeded::class);
        $recorder->reserve($context, 'gpt-5.1', null);
    }

    #[Test]
    public function usage_events_are_quiet_internal_records_excluded_from_normal_surfaces(): void
    {
        Queue::fake();
        config(['app.enable_task_pipeline' => true]);
        $user = User::factory()->create();

        $reservation = app(AiUsageRecorder::class)->reserve(
            new AiUsageContext($user, 'agent', 'flint', skill: 'flint-topics'),
            'gpt-5.1',
            100,
        );
        app(AiUsageRecorder::class)->complete($reservation, new AiTokenUsage(20, 10));

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
        $event = Event::findOrFail($reservation->eventId);
        $this->assertTrue($event->isInternal());
        $this->assertNull($event->embeddings);
        $this->assertSame(0, $user->integrations()->where('service', 'openai')->count());
        $this->assertSame(1, $user->allIntegrations()->where('service', 'openai')->count());
        $this->assertSame(0, Event::query()->withoutInternal()->whereKey($event->id)->count());
        $this->assertSame(1, app(EventFeed::class)->filter($user, 'openai')['total_count']);

        $summary = app(DaySummaryService::class)->generateSummary($user, now());
        $this->assertStringNotContainsString('used_ai', json_encode($summary));
        $this->assertSame(0, Integration::query()->external()->where('service', 'openai')->count());
        $this->assertDatabaseCount('activity_log', 0);
    }

    #[Test]
    public function embedding_cache_hits_do_not_increment_usage(): void
    {
        $embedding = array_fill(0, 1536, 0.0);
        $embedding[0] = 0.25;
        Http::fake(['api.openai.com/v1/embeddings' => Http::response([
            'data' => [['embedding' => $embedding]],
            'usage' => ['prompt_tokens' => 8, 'total_tokens' => 8],
        ])]);
        $user = User::factory()->create();
        $context = new AiUsageContext($user, 'embedding', 'search');
        $client = new EmbeddingClient;

        $client->embed('cache me once', usageContext: $context);
        $client->embed('cache me once', usageContext: $context);

        Http::assertSentCount(1);
        $event = Event::where('service', 'openai')->firstOrFail();
        $this->assertSame(1, $event->event_metadata['request_count']);
        $this->assertSame(8, (int) $event->value);
    }
}
