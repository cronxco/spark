<?php

namespace Tests\Feature\Flint;

use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\FlintDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Decision D-F4: one Flint integration per user, of type `assistant`. */
class FlintIntegrationIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function a_new_user_gets_one_assistant_integration(): void
    {
        $service = app(FlintDigestService::class);

        $first = $service->resolveIntegration($this->user);
        $second = $service->resolveIntegration($this->user);

        $this->assertSame('assistant', $first->instance_type);
        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(1, Integration::where('user_id', $this->user->id)->where('service', 'flint')->count());
    }

    #[Test]
    public function an_existing_legacy_row_is_reused_rather_than_duplicated(): void
    {
        $legacy = $this->flint('digest');

        $this->assertTrue(app(FlintDigestService::class)->resolveIntegration($this->user)->is($legacy));
    }

    #[Test]
    public function the_migration_renames_a_lone_digest_row(): void
    {
        $legacy = $this->flint('digest');

        $this->migrate();

        $this->assertSame('assistant', $legacy->fresh()->instance_type);
    }

    #[Test]
    public function the_migration_merges_a_digest_row_into_an_existing_assistant(): void
    {
        $assistant = $this->flint('assistant', now()->subYear());
        $legacy = $this->flint('digest');
        $digest = Event::factory()->create(['integration_id' => $legacy->id, 'service' => 'flint', 'action' => 'had_summary']);
        $other = User::factory()->create();
        $othersLegacy = $this->flint('digest', null, $other);

        $this->migrate();

        $this->assertSame($assistant->id, $digest->fresh()->integration_id);
        $this->assertSoftDeleted($legacy);
        $this->assertSame(1, Integration::where('user_id', $this->user->id)->where('service', 'flint')->count());
        $this->assertSame('assistant', $othersLegacy->fresh()->instance_type);
    }

    private function flint(string $type, $createdAt = null, ?User $user = null): Integration
    {
        return Integration::factory()->create(array_filter([
            'user_id' => ($user ?? $this->user)->id,
            'service' => 'flint',
            'instance_type' => $type,
            'created_at' => $createdAt,
        ]));
    }

    private function migrate(): void
    {
        (include database_path('migrations/2026_10_03_150000_move_flint_integrations_to_assistant.php'))->up();
    }
}
