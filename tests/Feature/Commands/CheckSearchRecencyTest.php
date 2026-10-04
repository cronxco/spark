<?php

namespace Tests\Feature\Commands;

use App\Models\EventObject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckSearchRecencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $queryFile;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 12:00:00');

        $this->user = User::factory()->create(['email' => 'will@example.test']);
        $this->queryFile = storage_path('framework/testing/recency-queries-' . uniqid() . '.txt');
        File::ensureDirectoryExists(dirname($this->queryFile));

        EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Tesco', 'content' => null, 'time' => now()->subYear()]);
        EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Big Tesco', 'content' => null, 'time' => now()]);
    }

    protected function tearDown(): void
    {
        File::delete($this->queryFile);
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_shows_how_results_move_when_recency_is_turned_on(): void
    {
        $this->artisan('search:recency-check', ['user' => 'will@example.test', 'queries' => ['Tesco'], '--weight' => 0.5])
            ->expectsOutputToContain('recency off vs weight 0.5, half-life 30 days')
            ->expectsOutputToContain('“Tesco”')
            ->expectsTable(['Kind', 'On', 'Off', 'Move', 'Title', 'Date'], [
                ['object', 1, 2, '▲1', 'Big Tesco', '2026-10-03'],
                ['object', 2, 1, '▼1', 'Tesco', '2025-10-03'],
            ])
            ->expectsOutputToContain('Top result changed in 1 of 1 query; 2 results moved.')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_reads_queries_from_a_file_and_reports_unchanged_results(): void
    {
        File::put($this->queryFile, "# real searches\nTesco\n\nnothing matches this\nTesco\n");

        $this->artisan('search:recency-check', ['user' => (string) $this->user->id, '--file' => $this->queryFile, '--weight' => 0.2])
            ->expectsOutputToContain('Comparing 2 queries')
            ->expectsOutputToContain('“nothing matches this”')
            ->expectsOutputToContain('No results.')
            ->expectsOutputToContain('Top result changed in 0 of 2 queries; 0 results moved.')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_warns_when_the_weight_is_zero(): void
    {
        $this->artisan('search:recency-check', ['user' => 'will@example.test', 'queries' => ['Tesco'], '--weight' => 0])
            ->expectsOutputToContain('both runs are identical')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_fails_for_an_unknown_user(): void
    {
        $this->artisan('search:recency-check', ['user' => 'nobody@example.test', 'queries' => ['Tesco']])
            ->expectsOutputToContain('No user found')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_fails_without_queries_or_with_an_unreadable_file(): void
    {
        $this->artisan('search:recency-check', ['user' => 'will@example.test'])
            ->expectsOutputToContain('No queries given')
            ->assertExitCode(Command::FAILURE);

        $this->artisan('search:recency-check', ['user' => 'will@example.test', '--file' => '/no/such/file.txt'])
            ->expectsOutputToContain('Cannot read query file')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_rejects_an_unknown_mode(): void
    {
        $this->artisan('search:recency-check', ['user' => 'will@example.test', 'queries' => ['Tesco'], '--mode' => 'tag'])
            ->expectsOutputToContain('Mode must be default or semantic')
            ->assertExitCode(Command::FAILURE);
    }
}
