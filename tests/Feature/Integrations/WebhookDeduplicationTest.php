<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Webhook\Slack\SlackEventsHook;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebhookDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'slack', 'account_id' => 'slack_secret']);
        Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'slack',
            'account_id' => 'slack_secret',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_retried_delivery_with_the_same_event_id_is_queued_once(): void
    {
        $payload = ['event_id' => 'Ev123', 'event' => ['type' => 'message', 'text' => 'hi']];

        $this->postJson('/webhook/slack/slack_secret', $payload)->assertOk()->assertJson(['status' => 'success']);
        $this->postJson('/webhook/slack/slack_secret', $payload, ['X-Slack-Retry-Num' => '1'])
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        Queue::assertPushed(SlackEventsHook::class, 1);
    }

    #[Test]
    public function a_delivery_id_header_is_remembered_for_a_day(): void
    {
        Carbon::setTestNow('2026-06-15 12:00');
        $this->postJson('/webhook/slack/slack_secret', ['text' => 'one'], ['Webhook-Id' => 'abc'])->assertOk();

        Carbon::setTestNow('2026-06-16 11:00');
        $this->postJson('/webhook/slack/slack_secret', ['text' => 'one, resent'], ['Webhook-Id' => 'abc'])
            ->assertJson(['status' => 'duplicate']);

        Carbon::setTestNow('2026-06-16 12:01');
        $this->postJson('/webhook/slack/slack_secret', ['text' => 'one, resent'], ['Webhook-Id' => 'abc'])
            ->assertJson(['status' => 'success']);

        Queue::assertPushed(SlackEventsHook::class, 2);
    }

    #[Test]
    public function an_identical_body_without_an_id_is_only_dropped_within_a_few_minutes(): void
    {
        $payload = ['event' => ['type' => 'message', 'text' => 'same']];

        Carbon::setTestNow('2026-06-15 12:00');
        $this->postJson('/webhook/slack/slack_secret', $payload)->assertJson(['status' => 'success']);

        Carbon::setTestNow('2026-06-15 12:04');
        $this->postJson('/webhook/slack/slack_secret', $payload)->assertJson(['status' => 'duplicate']);

        Carbon::setTestNow('2026-06-15 12:10');
        $this->postJson('/webhook/slack/slack_secret', $payload)->assertJson(['status' => 'success']);

        Queue::assertPushed(SlackEventsHook::class, 2);
    }

    #[Test]
    public function different_deliveries_are_all_queued(): void
    {
        $this->postJson('/webhook/slack/slack_secret', ['event_id' => 'Ev1'])->assertJson(['status' => 'success']);
        $this->postJson('/webhook/slack/slack_secret', ['event_id' => 'Ev2'])->assertJson(['status' => 'success']);

        Queue::assertPushed(SlackEventsHook::class, 2);
    }

    #[Test]
    public function an_unknown_secret_does_not_claim_the_delivery(): void
    {
        $payload = ['event_id' => 'Ev9'];

        $this->postJson('/webhook/slack/wrong_secret', $payload)->assertNotFound();
        $this->postJson('/webhook/slack/slack_secret', $payload)->assertJson(['status' => 'success']);
    }
}
