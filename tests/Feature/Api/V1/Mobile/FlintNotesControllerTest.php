<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class FlintNotesControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true, 'app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $this->user->setTimezone('Europe/London');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
    }

    #[Test]
    public function notes_use_authored_time_titles_and_disambiguate_same_minute(): void
    {
        $first = $this->postJson('/api/v1/mobile/flint/notes', $this->payload())->assertCreated();
        $second = $this->postJson('/api/v1/mobile/flint/notes', $this->payload())->assertCreated();

        $first->assertJsonPath('data.title', 'Note to Flint 14/09/26 13:17');
        $second->assertJsonPath('data.title', 'Note to Flint 14/09/26 13:17 (2)');
        $this->assertSame(2, EventObject::where('type', 'flint_note')->count());
    }

    #[Test]
    public function create_is_idempotent_and_detects_mutation_conflicts(): void
    {
        $payload = $this->payload();
        $created = $this->postJson('/api/v1/mobile/flint/notes', $payload)->assertCreated();
        $this->postJson('/api/v1/mobile/flint/notes', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'));

        $this->postJson('/api/v1/mobile/flint/notes', [...$payload, 'body' => 'Different'])
            ->assertConflict();
    }

    #[Test]
    public function context_links_are_tenant_scoped_relationships(): void
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);
        $payload = $this->payload(['context_links' => [['type' => 'event', 'id' => $event->id]]]);

        $this->postJson('/api/v1/mobile/flint/notes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.context_links.0.id', $event->id);
        $this->assertSame(1, Relationship::where('type', 'references')->count());

        $other = User::factory()->create();
        $otherIntegration = Integration::factory()->create(['user_id' => $other->id]);
        $otherEvent = Event::factory()->create(['integration_id' => $otherIntegration->id]);
        $this->postJson('/api/v1/mobile/flint/notes', $this->payload([
            'context_links' => [['type' => 'event', 'id' => $otherEvent->id]],
        ]))->assertUnprocessable();
    }

    #[Test]
    public function list_and_delete_are_tenant_scoped_and_idempotent(): void
    {
        $id = $this->postJson('/api/v1/mobile/flint/notes', $this->payload())->json('data.id');
        $this->getJson('/api/v1/mobile/flint/notes')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/mobile/flint/notes/{$id}")->assertNoContent();
        $this->deleteJson("/api/v1/mobile/flint/notes/{$id}")->assertNoContent();
        $this->getJson('/api/v1/mobile/flint/notes')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function note_prose_is_excluded_from_activity_logs(): void
    {
        $body = 'Private note prose that must never reach audit properties.';
        $id = $this->postJson('/api/v1/mobile/flint/notes', $this->payload(['body' => $body]))
            ->assertCreated()
            ->json('data.id');

        $activities = Activity::query()->where('subject_id', $id)->get();
        $this->assertStringNotContainsString($body, $activities->toJson());
        $this->assertStringNotContainsString('Note to Flint', $activities->toJson());
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'client_mutation_id' => (string) Str::uuid(),
            'authored_at' => '2026-09-14T13:17:00+01:00',
            'body' => 'Keep Friday evening free after the train.',
            'context_links' => [],
            'consent_version' => 'flint-note-v1',
        ], $overrides);
    }
}
