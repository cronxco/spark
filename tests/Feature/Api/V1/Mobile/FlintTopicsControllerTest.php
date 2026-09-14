<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\FlintTopicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintTopicsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function lists_the_users_topics_newest_first(): void
    {
        // updated_at isn't fillable on EventObject, and save() re-stamps it
        // regardless — forceFill + disabled timestamps is the only way to
        // backdate it for the ordering assertion below.
        $older = $this->topic('Edinburgh trip with Dan', kind: 'thematic', status: 'dormant');
        $older->timestamps = false;
        $older->forceFill(['updated_at' => now()->subDays(5)])->save();

        $newer = $this->topic('US–Iran escalation', kind: 'strategic', status: 'active');

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/topics')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $newer->id)
            ->assertJsonPath('data.0.title', 'US–Iran escalation')
            ->assertJsonPath('data.0.kind', 'strategic')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.1.id', (string) $older->id)
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function filters_by_status(): void
    {
        $this->topic('Active thread', status: 'active');
        $this->topic('Dormant thread', status: 'dormant');

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/topics?status=dormant')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Dormant thread');
    }

    #[Test]
    public function only_returns_the_authenticated_users_topics(): void
    {
        $other = User::factory()->create();
        EventObject::factory()->create([
            'user_id' => $other->id,
            'concept' => 'flint',
            'type' => 'topic',
            'title' => 'Someone else\'s thread',
            'metadata' => ['kind' => 'thematic', 'status' => 'active'],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/topics')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function detail_returns_versioned_owned_evidence(): void
    {
        $topic = $this->topic('Quarterly planning', kind: 'strategic');
        $integration = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'flint']);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'event_metadata' => ['title' => 'Morning Digest', 'local_date' => '2026-09-14', 'period' => 'morning'],
        ]);
        app(FlintTopicService::class)->update($this->user, $topic->id, ['related_event_id' => $event->id]);
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson("/api/v1/mobile/flint/topics/{$topic->id}")
            ->assertOk()
            ->assertJsonPath('data.id', (string) $topic->id)
            ->assertJsonPath('data.mentions.0.digest_id', (string) $event->id)
            ->assertJsonPath('data.mentions.0.deep_link', 'spark://digest/' . $event->id)
            ->assertJsonStructure(['data' => ['version']]);
    }

    #[Test]
    public function detail_does_not_reveal_another_users_topic(): void
    {
        $other = User::factory()->create();
        $topic = EventObject::factory()->create([
            'user_id' => $other->id,
            'concept' => 'flint',
            'type' => 'topic',
            'title' => 'Private thread',
        ]);
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson("/api/v1/mobile/flint/topics/{$topic->id}")->assertNotFound();
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/flint/topics')->assertStatus(401);
    }

    private function topic(string $title, string $kind = 'thematic', string $status = 'active'): EventObject
    {
        return EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'topic',
            'title' => $title,
            'metadata' => ['kind' => $kind, 'status' => $status],
        ]);
    }
}
