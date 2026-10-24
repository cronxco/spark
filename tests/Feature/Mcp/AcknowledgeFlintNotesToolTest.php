<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\AcknowledgeFlintNotesTool;
use App\Models\EventObject;
use App\Models\User;
use App\Services\Flint\FlintRunToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AcknowledgeFlintNotesToolTest extends TestCase
{
    use RefreshDatabase;

    private function note(User $user, array $metadata = []): EventObject
    {
        return EventObject::factory()->create([
            'user_id' => $user->id, 'concept' => 'document', 'type' => 'flint_note',
            'content' => 'A note', 'metadata' => ['consent_version' => 'flint-note-v1', ...$metadata],
        ]);
    }

    #[Test]
    public function it_acknowledges_owned_notes_and_records_the_routine(): void
    {
        $user = User::factory()->create();
        $note = $this->note($user);
        $other = $this->note(User::factory()->create());

        SparkServer::actingAs($user)->tool(AcknowledgeFlintNotesTool::class, [
            'note_ids' => [(string) $note->id, (string) $other->id],
            'routine' => 'digest',
        ])->assertOk()->assertSee((string) $note->id)->assertSee('not_found');

        $note->refresh();
        $this->assertNotNull($note->metadata['acknowledged_at']);
        $this->assertSame('digest', $note->metadata['acknowledged_by']['routine']);
        $this->assertSame('flint-note-v1', $note->metadata['consent_version']);
        $this->assertArrayNotHasKey('acknowledged_at', $other->refresh()->metadata);
    }

    #[Test]
    public function it_records_the_run_uuid_from_a_verified_run_token_and_keeps_first_acknowledgement(): void
    {
        $user = User::factory()->create();
        $note = $this->note($user);
        $token = app(FlintRunToken::class)->issue(['user_id' => (string) $user->id, 'run_uuid' => 'run-1', 'routine' => 'topics']);

        SparkServer::actingAs($user)->tool(AcknowledgeFlintNotesTool::class, ['note_ids' => [(string) $note->id], 'run_token' => $token])->assertOk();
        $first = $note->refresh()->metadata['acknowledged_at'];
        $this->assertSame('run-1', $note->metadata['acknowledged_by']['run_uuid']);

        SparkServer::actingAs($user)->tool(AcknowledgeFlintNotesTool::class, ['note_ids' => [(string) $note->id], 'routine' => 'digest'])
            ->assertOk()->assertSee('already_acknowledged');
        $this->assertSame($first, $note->refresh()->metadata['acknowledged_at']);
        $this->assertSame('topics', $note->metadata['acknowledged_by']['routine']);
    }

    #[Test]
    public function it_rejects_invalid_ids_and_foreign_tokens(): void
    {
        $user = User::factory()->create();
        $note = $this->note($user);
        $token = app(FlintRunToken::class)->issue(['user_id' => (string) User::factory()->create()->id, 'run_uuid' => 'x']);

        SparkServer::actingAs($user)->tool(AcknowledgeFlintNotesTool::class, ['note_ids' => ['nope']])->assertHasErrors();
        SparkServer::actingAs($user)->tool(AcknowledgeFlintNotesTool::class, ['note_ids' => [(string) $note->id], 'run_token' => $token])->assertHasErrors();
        $this->assertArrayNotHasKey('acknowledged_at', $note->refresh()->metadata);
    }
}
