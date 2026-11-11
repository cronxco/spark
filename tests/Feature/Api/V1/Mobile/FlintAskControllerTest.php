<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Ai\Agents\AskFlintAgent;
use App\Http\Resources\Compact\CompactEventResource;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Support\SparkAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ask Flint over the mobile API: answers, remembered threads, changes that
 * apply at once with an Undo receipt, and the limits on what a turn may do.
 */
class FlintAskControllerTest extends TestCase
{
    use RefreshDatabase;

    private const ASK = '/api/v1/mobile/flint/ask';

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true, 'services.openai.daily_token_cap' => 0]);

        $this->user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo']);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);
    }

    #[Test]
    public function it_answers_and_remembers_the_conversation(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        AskFlintAgent::fake(['Your sleep score was 82.']);

        $response = $this->postJson(self::ASK, ['message' => 'How did I sleep?'])
            ->assertOk()
            ->assertJsonPath('contract_version', 1)
            ->assertJsonPath('message.role', 'assistant')
            ->assertJsonPath('message.text', 'Your sleep score was 82.')
            ->assertJsonPath('message.changes', []);

        $conversationId = $response->json('conversation_id');
        $this->assertNotNull($conversationId);

        $this->getJson(self::ASK . '/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.title', 'How did I sleep?');

        $this->getJson(self::ASK . "/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.role', 'user')
            ->assertJsonPath('data.0.text', 'How did I sleep?')
            ->assertJsonPath('data.1.role', 'assistant')
            ->assertJsonPath('data.1.text', 'Your sleep score was 82.');
    }

    #[Test]
    public function a_follow_up_continues_the_same_conversation(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        AskFlintAgent::fake(['82.', 'Seven hours forty.']);

        $conversationId = $this->postJson(self::ASK, ['message' => 'Sleep score?'])->assertOk()->json('conversation_id');

        $this->postJson(self::ASK, ['message' => 'And how long?', 'conversation_id' => $conversationId])
            ->assertOk()
            ->assertJsonPath('conversation_id', $conversationId)
            ->assertJsonPath('message.text', 'Seven hours forty.');

        $this->getJson(self::ASK . "/conversations/{$conversationId}")->assertOk()->assertJsonCount(4, 'data');
        $this->getJson(self::ASK . '/conversations')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function another_users_conversation_cannot_be_read_continued_or_deleted(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        AskFlintAgent::fake(['Mine.', 'Should not be reached.']);
        $conversationId = $this->postJson(self::ASK, ['message' => 'Hello'])->assertOk()->json('conversation_id');

        Sanctum::actingAs(User::factory()->create(), SparkAbility::MOBILE_SESSION);

        $this->getJson(self::ASK . "/conversations/{$conversationId}")->assertNotFound();
        $this->postJson(self::ASK, ['message' => 'Let me in', 'conversation_id' => $conversationId])->assertNotFound();
        $this->deleteJson(self::ASK . "/conversations/{$conversationId}")->assertNotFound();
        $this->getJson(self::ASK . '/conversations')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_conversation_can_be_deleted_by_its_owner(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        AskFlintAgent::fake(['Hi.']);
        $conversationId = $this->postJson(self::ASK, ['message' => 'Hello'])->assertOk()->json('conversation_id');

        $this->deleteJson(self::ASK . "/conversations/{$conversationId}")->assertNoContent();

        $this->getJson(self::ASK . "/conversations/{$conversationId}")->assertNotFound();
        $this->assertDatabaseMissing('agent_conversation_messages', ['conversation_id' => $conversationId]);
    }

    #[Test]
    public function a_tag_is_applied_at_once_and_can_be_undone(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->event();
        AskFlintAgent::fake([
            new ToolCall('call-1', 'tag-event', ['event_id' => $event->id, 'name' => 'work lunch']),
            'Tagged it as work lunch.',
        ]);

        $response = $this->postJson(self::ASK, ['message' => 'Tag my Pret lunch as work lunch'])
            ->assertOk()
            ->assertJsonCount(1, 'message.changes')
            ->assertJsonPath('message.changes.0.operation', 'tag')
            ->assertJsonPath('message.changes.0.kind', 'event')
            ->assertJsonPath('message.changes.0.id', $event->id)
            ->assertJsonPath('message.changes.0.undo.available', true)
            ->assertJsonPath('message.changes.0.undo.undone', false);

        $this->assertSame(1, $event->fresh()->tags()->count());

        $receiptId = $response->json('message.changes.0.receipt_id');
        $this->postJson("/api/v1/mobile/actions/receipts/{$receiptId}/undo")->assertOk();

        $this->assertSame(0, $event->fresh()->tags()->count());

        $this->getJson(self::ASK . '/conversations/' . $response->json('conversation_id'))
            ->assertOk()
            ->assertJsonPath('data.1.changes.0.receipt_id', $receiptId)
            ->assertJsonPath('data.1.changes.0.undo.available', false)
            ->assertJsonPath('data.1.changes.0.undo.undone', true);
    }

    #[Test]
    public function an_edit_keeps_the_previous_values_for_undo(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $object = EventObject::factory()->create(['user_id' => $this->user->id, 'concept' => 'merchant', 'type' => 'monzo_merchant', 'title' => 'PRET A MANGER 0412']);
        AskFlintAgent::fake([
            new ToolCall('call-1', 'update-record', ['kind' => 'object', 'id' => $object->id, 'title' => 'Pret A Manger']),
            'Renamed it to Pret A Manger.',
        ]);

        $response = $this->postJson(self::ASK, ['message' => 'Rename that merchant to Pret A Manger'])
            ->assertOk()
            ->assertJsonPath('message.changes.0.operation', 'update')
            ->assertJsonPath('message.changes.0.kind', 'object')
            ->assertJsonPath('message.changes.0.title', 'Pret A Manger');

        $this->assertSame('Pret A Manger', $object->fresh()->title);
        $this->assertStringContainsString('PRET A MANGER 0412', $response->json('message.changes.0.summary'));

        $this->postJson('/api/v1/mobile/actions/receipts/' . $response->json('message.changes.0.receipt_id') . '/undo')->assertOk();

        $this->assertSame('PRET A MANGER 0412', $object->fresh()->title);
    }

    #[Test]
    public function a_note_is_set_and_undo_restores_the_one_before(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->event();
        AskFlintAgent::fake([
            new ToolCall('call-1', 'set-event-note', ['event_id' => $event->id, 'note' => 'Client lunch with Sam']),
            'Noted.',
            new ToolCall('call-2', 'set-event-note', ['event_id' => $event->id, 'note' => 'Team lunch']),
            'Updated.',
        ]);

        $this->postJson(self::ASK, ['message' => 'Note that this was a client lunch with Sam'])->assertOk();
        $second = $this->postJson(self::ASK, ['message' => 'Actually make it team lunch'])
            ->assertOk()
            ->assertJsonPath('message.changes.0.operation', 'note');

        $this->assertSame('Team lunch', $this->noteOn($event));

        $this->postJson('/api/v1/mobile/actions/receipts/' . $second->json('message.changes.0.receipt_id') . '/undo')->assertOk();

        $this->assertSame('Client lunch with Sam', $this->noteOn($event));
    }

    #[Test]
    public function deleting_an_event_moves_it_to_the_bin_and_undo_restores_it(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->event();
        AskFlintAgent::fake([
            new ToolCall('call-1', 'delete-event', ['event_id' => $event->id]),
            'Moved it to the bin.',
        ]);

        $response = $this->postJson(self::ASK, ['message' => 'Delete that duplicate transaction'])
            ->assertOk()
            ->assertJsonPath('message.changes.0.operation', 'delete')
            ->assertJsonPath('message.changes.0.undo.available', true);

        $this->assertSoftDeleted('events', ['id' => $event->id]);

        $this->postJson('/api/v1/mobile/actions/receipts/' . $response->json('message.changes.0.receipt_id') . '/undo')->assertOk();

        $this->assertNotSoftDeleted('events', ['id' => $event->id]);
    }

    #[Test]
    public function a_change_to_another_users_record_is_refused(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $other = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $other->id, 'service' => 'monzo']);
        $theirs = Event::factory()->create([
            'integration_id' => Integration::factory()->create(['user_id' => $other->id, 'integration_group_id' => $group->id, 'service' => 'monzo'])->id,
            'service' => 'monzo',
        ]);
        AskFlintAgent::fake([
            new ToolCall('call-1', 'delete-event', ['event_id' => $theirs->id]),
            'I could not find that event.',
        ]);

        $this->postJson(self::ASK, ['message' => 'Delete it'])
            ->assertOk()
            ->assertJsonPath('message.changes', []);

        $this->assertNotSoftDeleted('events', ['id' => $theirs->id]);
    }

    #[Test]
    public function links_to_real_records_become_references_and_invented_ones_lose_their_link(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->event();
        $invented = '00000000-0000-4000-8000-000000000000';
        AskFlintAgent::fake(["You spent at [Pret](/events/{$event->id}) and [Costa](/events/{$invented})."]);

        $this->postJson(self::ASK, ['message' => 'Where did I buy coffee?'])
            ->assertOk()
            ->assertJsonPath('message.text', "You spent at [Pret](/events/{$event->id}) and Costa.")
            ->assertJsonCount(1, 'message.references')
            ->assertJsonPath('message.references.0.kind', 'event')
            ->assertJsonPath('message.references.0.id', $event->id);
    }

    #[Test]
    public function it_streams_the_answer_as_server_sent_events(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        AskFlintAgent::fake(['Your sleep score was 82.']);

        $response = $this->postJson(self::ASK, ['message' => 'How did I sleep?', 'stream' => true])->assertOk();

        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('event: conversation', $body);
        $this->assertStringContainsString('event: delta', $body);
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringNotContainsString('event: error', $body);
        $this->assertStringContainsString('Your sleep score was 82.', $body);

        $this->getJson(self::ASK . '/conversations')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_message_is_required_and_bounded(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        AskFlintAgent::fake()->preventStrayPrompts();

        $this->postJson(self::ASK, [])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->postJson(self::ASK, ['message' => str_repeat('a', 4001)])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->postJson(self::ASK, ['message' => 'Hi', 'conversation_id' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
    }

    #[Test]
    public function asking_stops_when_the_daily_allowance_is_spent(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        config(['services.openai.daily_token_cap' => 10]);
        AskFlintAgent::fake()->preventStrayPrompts();

        $this->postJson(self::ASK, ['message' => 'How did I sleep?'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'daily_allowance_reached');
    }

    #[Test]
    public function a_session_without_flint_read_cannot_ask(): void
    {
        Sanctum::actingAs($this->user, [SparkAbility::MOBILE_SESSION_MARKER, 'data:read']);
        AskFlintAgent::fake()->preventStrayPrompts();

        $this->postJson(self::ASK, ['message' => 'Hello'])->assertForbidden();
        $this->getJson(self::ASK . '/conversations')->assertForbidden();
    }

    private function event(): Event
    {
        $target = EventObject::factory()->create(['user_id' => $this->user->id, 'concept' => 'merchant', 'type' => 'monzo_merchant', 'title' => 'Pret A Manger']);

        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'action' => 'card_payment_to',
            'target_id' => $target->id,
            'time' => now()->subDay(),
        ]);
    }

    private function noteOn(Event $event): ?string
    {
        return $event->fresh()->blocks()
            ->where('block_type', CompactEventResource::NOTE_BLOCK_TYPE)
            ->first()
            ?->getContent();
    }
}
