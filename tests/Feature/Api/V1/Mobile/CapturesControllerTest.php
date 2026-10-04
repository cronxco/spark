<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CapturesControllerTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        Queue::fake();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/captures', ['kind' => 'text', 'idempotency_key' => 'k1', 'text' => 'hi'])
            ->assertForbidden();
    }

    #[Test]
    public function free_text_lands_in_the_inbox_as_an_event(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'data:write']);

        $response = $this->postJson('/api/v1/mobile/captures', [
            'kind' => 'text',
            'idempotency_key' => 'share-1',
            'text' => "Ask Sam about the boiler service\nbefore Friday",
        ])->assertCreated()
            ->assertJsonPath('capture_receipt.kind', 'text')
            ->assertJsonPath('capture_receipt.status', 'accepted')
            ->assertJsonPath('capture_receipt.destination.type', 'event');

        $event = Event::findOrFail($response->json('capture_receipt.id'));
        $this->assertSame('captured_text', $event->action);
        $this->assertSame("Ask Sam about the boiler service\nbefore Friday", $event->event_metadata['text']);
        $this->assertSame('pending', $event->event_metadata['triage']);
        $this->assertSame('Inbox', $event->target->title);
        $this->assertSame('capture_inbox', $event->target->type);
        $this->assertSame($this->user->id, $event->target->user_id);
    }

    #[Test]
    public function a_retry_with_the_same_key_returns_the_first_receipt(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
        $payload = ['kind' => 'text', 'idempotency_key' => 'share-2', 'text' => 'Remember the milk'];

        $first = $this->postJson('/api/v1/mobile/captures', $payload)->assertCreated();
        $this->postJson('/api/v1/mobile/captures', $payload)
            ->assertOk()
            ->assertJsonPath('capture_receipt.id', $first->json('capture_receipt.id'));

        $this->assertSame(1, Event::where('action', 'captured_text')->count());
    }

    #[Test]
    public function two_captures_share_one_inbox(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/captures', ['kind' => 'text', 'idempotency_key' => 'a', 'text' => 'One'])->assertCreated();
        $this->postJson('/api/v1/mobile/captures', ['kind' => 'text', 'idempotency_key' => 'b', 'text' => 'Two'])->assertCreated();

        $this->assertSame(1, EventObject::where('user_id', $this->user->id)->where('type', 'capture_inbox')->count());
        $this->assertSame(2, Event::where('action', 'captured_text')->count());
    }

    #[Test]
    public function the_same_key_from_another_user_is_a_separate_capture(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($other, ['ios:read', 'ios:write']);
        $this->postJson('/api/v1/mobile/captures', ['kind' => 'text', 'idempotency_key' => 'shared', 'text' => 'Theirs'])->assertCreated();

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
        $this->postJson('/api/v1/mobile/captures', ['kind' => 'text', 'idempotency_key' => 'shared', 'text' => 'Mine'])
            ->assertCreated();
    }

    #[Test]
    public function a_url_becomes_a_bookmark(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->postJson('/api/v1/mobile/captures', [
            'kind' => 'url',
            'idempotency_key' => 'share-3',
            'url' => 'https://example.com/article',
        ])->assertCreated()
            ->assertJsonPath('capture_receipt.kind', 'url')
            ->assertJsonPath('capture_receipt.destination.type', 'object');

        $bookmark = EventObject::findOrFail($response->json('capture_receipt.destination.id'));
        $this->assertSame('bookmark', $bookmark->concept);
        $this->assertSame('https://example.com/article', $bookmark->url);
    }

    #[Test]
    public function an_unsafe_url_is_rejected(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/captures', ['kind' => 'url', 'idempotency_key' => 'x', 'url' => 'http://127.0.0.1/admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    #[Test]
    public function an_image_is_stored_on_its_own_object(): void
    {
        Storage::fake(config('media-library.disk_name'));
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->postJson('/api/v1/mobile/captures', [
            'kind' => 'image',
            'idempotency_key' => 'share-4',
            'image' => self::PNG,
            'title' => 'Whiteboard',
        ])->assertCreated()
            ->assertJsonPath('capture_receipt.kind', 'image');

        $event = Event::findOrFail($response->json('capture_receipt.id'));
        $this->assertSame('captured_image', $event->action);
        $this->assertSame('Whiteboard', $event->target->title);
        $this->assertCount(1, $event->target->getMedia('downloaded_images'));
    }

    #[Test]
    public function a_file_that_is_not_an_image_is_rejected_and_nothing_is_kept(): void
    {
        Storage::fake(config('media-library.disk_name'));
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/captures', [
            'kind' => 'image',
            'idempotency_key' => 'share-5',
            'image' => base64_encode('%PDF-1.4 not an image'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertSame(0, Event::where('action', 'captured_image')->count());
        $this->assertSame(0, EventObject::where('type', 'captured_image')->count());
    }

    #[Test]
    public function the_payload_must_match_the_kind(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/captures', ['kind' => 'text', 'idempotency_key' => 'k', 'url' => 'https://example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['text', 'url']);

        $this->postJson('/api/v1/mobile/captures', ['kind' => 'video', 'idempotency_key' => 'k', 'text' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');

        $this->postJson('/api/v1/mobile/captures', ['kind' => 'text', 'text' => 'no key'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }
}
