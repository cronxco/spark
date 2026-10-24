<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\EventObject;
use App\Models\Person;
use App\Models\User;
use App\Support\SparkAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MobileSessionAbilities;
use Tests\TestCase;

class LogIntentsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
    }

    #[Test]
    public function requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read']));

        $this->classify('coffee')->assertForbidden();
        $this->commit()->assertForbidden();
    }

    #[Test]
    public function flint_handle_always_classifies_as_a_note_and_is_not_matched_as_a_person(): void
    {
        Person::create(['user_id' => $this->user->id, 'type' => 'person', 'title' => 'Flint Smith', 'time' => now(), 'metadata' => []]);

        $this->classify('coffee with @dan, @flint remember I hate decaf', ['intent' => 'log', 'confidence' => 0.99])
            ->assertOk()
            ->assertJsonPath('intent', 'flint_note')
            ->assertJsonPath('needs_choice', false)
            ->assertJsonPath('text', 'coffee with @dan, remember I hate decaf')
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.name', 'dan');
    }

    #[Test]
    public function prefixes_force_command_and_search(): void
    {
        $this->classify('/sync Monzo')->assertJsonPath('intent', 'command')->assertJsonPath('text', 'sync Monzo');
        $this->classify('?dentist')->assertJsonPath('intent', 'search')->assertJsonPath('text', 'dentist');
        $this->classify('/')->assertJsonPath('intent', 'unsure');
    }

    #[Test]
    public function low_confidence_or_missing_hint_asks_before_saving(): void
    {
        $this->classify('2 pints of Guinness', ['intent' => 'log', 'confidence' => 0.9])
            ->assertJsonPath('intent', 'log')
            ->assertJsonPath('needs_choice', false);

        $this->classify('sync', ['intent' => 'command', 'confidence' => 0.5])
            ->assertJsonPath('intent', 'unsure')
            ->assertJsonPath('needs_choice', true)
            ->assertJsonPath('candidates', ['command', 'log', 'search']);

        $this->classify('something')->assertJsonPath('reason', 'no_hint')->assertJsonPath('needs_choice', true);
    }

    #[Test]
    public function a_flint_note_is_never_inferred_from_a_hint(): void
    {
        $this->classify('remember I hate decaf', ['intent' => 'flint_note', 'confidence' => 1])
            ->assertJsonPath('intent', 'unsure');
    }

    #[Test]
    public function classify_writes_nothing(): void
    {
        $this->classify('@flint hello')->assertOk();

        $this->assertSame(0, EventObject::where('type', 'flint_note')->count());
    }

    #[Test]
    public function commit_creates_a_flint_note_idempotently(): void
    {
        $first = $this->commit()->assertCreated()
            ->assertJsonPath('status', 'saved')
            ->assertJsonPath('note.body', 'stop asking about sleep');
        $this->commit()->assertOk()->assertJsonPath('note.id', $first->json('note.id'))->assertJsonPath('created', false);

        $this->assertSame(1, EventObject::where('type', 'flint_note')->count());
    }

    #[Test]
    public function at_flint_cannot_be_committed_as_anything_else(): void
    {
        $this->commit(['intent' => 'log'])->assertUnprocessable()->assertJsonValidationErrors(['intent']);
        $this->assertSame(0, EventObject::where('type', 'flint_note')->count());
    }

    #[Test]
    public function a_note_needs_flint_write(): void
    {
        Sanctum::actingAs($this->user, ['data:write']);

        $this->commit()->assertForbidden();
        $this->assertSame(0, EventObject::where('type', 'flint_note')->count());
    }

    #[Test]
    public function other_intents_are_acknowledged_without_writing(): void
    {
        $this->commit(['text' => 'coffee with @dan', 'intent' => 'log', 'suggested_intent' => 'log'])
            ->assertOk()
            ->assertJsonPath('status', 'client_action')
            ->assertJsonPath('intent', 'log');

        $this->assertSame(0, EventObject::where('type', 'flint_note')->count());
    }

    #[Test]
    public function telemetry_records_overrides_without_the_text(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            if ($event->message === 'quick_log.intent') {
                $logged[] = $event->context;
            }
        });

        $this->commit(['text' => 'secret dentist plan', 'intent' => 'search', 'suggested_intent' => 'log', 'confidence' => 0.83])->assertOk();

        $this->assertCount(1, $logged);
        $this->assertSame('override', $logged[0]['source']);
        $this->assertTrue($logged[0]['overridden']);
        $this->assertSame('log', $logged[0]['suggested']);
        $this->assertSame('search', $logged[0]['chosen']);
        $this->assertSame(0.8, $logged[0]['confidence_bucket']);
        $this->assertStringNotContainsString('dentist', json_encode($logged[0]));
    }

    #[Test]
    public function validates_the_request(): void
    {
        $this->postJson('/api/v1/mobile/log/intents', ['intent' => 'unsure'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key', 'text', 'intent', 'occurred_at']);
        $this->postJson('/api/v1/mobile/log/intents/classify', ['text' => 'x', 'hint' => ['intent' => 'log']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hint.confidence']);
    }

    private function classify(string $text, ?array $hint = null)
    {
        return $this->postJson('/api/v1/mobile/log/intents/classify', array_filter(['text' => $text, 'hint' => $hint]));
    }

    private function commit(array $overrides = [])
    {
        return $this->postJson('/api/v1/mobile/log/intents', [
            'idempotency_key' => 'k1',
            'text' => '@flint stop asking about sleep',
            'intent' => 'flint_note',
            'occurred_at' => now()->subMinute()->toIso8601String(),
            ...$overrides,
        ]);
    }
}
