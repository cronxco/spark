<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\GetFlintNotesTool;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GetFlintNotesToolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_recent_owned_notes_with_an_update_watermark(): void
    {
        $user = User::factory()->create();
        $note = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'document',
            'type' => 'flint_note',
            'title' => 'Note to Flint',
            'content' => 'Monday is not an NWD.',
            'time' => now()->subHour(),
            'updated_at' => now()->subMinutes(5),
            'metadata' => ['consent_version' => 'flint-note-v1'],
        ]);
        EventObject::factory()->create([
            'user_id' => User::factory(),
            'concept' => 'document',
            'type' => 'flint_note',
            'content' => 'Private to someone else',
            'updated_at' => now(),
        ]);

        $response = SparkServer::actingAs($user)->tool(GetFlintNotesTool::class, [
            'updated_since' => now()->subDay()->toIso8601String(),
        ]);

        $response->assertOk()
            ->assertSee((string) $note->id)
            ->assertSee('Monday is not an NWD')
            ->assertDontSee('Private to someone else')
            ->assertSee('watermark');
    }

    #[Test]
    public function it_excludes_deleted_and_pre_watermark_notes(): void
    {
        $user = User::factory()->create();
        $deleted = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'document',
            'type' => 'flint_note',
            'content' => 'Deleted note',
            'updated_at' => now(),
        ]);
        $deleted->delete();
        EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'document',
            'type' => 'flint_note',
            'content' => 'Old note',
            'updated_at' => now()->subDays(40),
        ]);

        SparkServer::actingAs($user)->tool(GetFlintNotesTool::class, [
            'updated_since' => now()->subDays(30)->toIso8601String(),
        ])->assertOk()->assertDontSee('Deleted note')->assertDontSee('Old note');
    }

    #[Test]
    public function a_targeted_query_can_recover_an_older_still_relevant_correction(): void
    {
        $user = User::factory()->create();
        EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'document',
            'type' => 'flint_note',
            'content' => 'The Montreal booking date moved by one day.',
            'time' => now()->subMonths(3),
            'updated_at' => now()->subMonths(3),
        ]);

        SparkServer::actingAs($user)->tool(GetFlintNotesTool::class, [
            'query' => 'Montreal',
        ])->assertOk()->assertSee('booking date moved');
    }
}
