<?php

namespace Tests\Feature\Console;

use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PruneActivityLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_dry_run_counts_and_deletes_nothing(): void
    {
        $this->seedRows();

        $this->artisan('activity-log:prune')
            ->expectsOutputToContain('Dry run: nothing was deleted')
            ->assertSuccessful();

        $this->assertSame(6, DB::table('activity_log')->count());
    }

    #[Test]
    public function execute_keeps_change_rows_for_ninety_days_and_security_rows_for_a_year(): void
    {
        $ids = $this->seedRows();

        $this->artisan('activity-log:prune', ['--execute' => true, '--batch-size' => 1])
            ->expectsOutputToContain('Deleted 3 activity log row(s).')
            ->assertSuccessful();

        $remaining = DB::table('activity_log')->pluck('id')->all();
        sort($remaining);
        $expected = [$ids['recent_change'], $ids['old_integration'], $ids['old_security']];
        sort($expected);
        $this->assertSame($expected, $remaining);
    }

    /**
     * @return array<string, int>
     */
    private function seedRows(): array
    {
        $now = now();

        return [
            'recent_change' => $this->row('changelog', EventObject::class, $now->copy()->subDays(30)),
            'old_change' => $this->row('changelog', EventObject::class, $now->copy()->subDays(91)),
            'old_unlabelled' => $this->row(null, null, $now->copy()->subDays(200)),
            'old_integration' => $this->row('changelog', Integration::class, $now->copy()->subDays(200)),
            'old_security' => $this->row('security', User::class, $now->copy()->subDays(300)),
            'expired_security' => $this->row('security', null, $now->copy()->subDays(366)),
        ];
    }

    private function row(?string $logName, ?string $subjectType, $createdAt): int
    {
        return DB::table('activity_log')->insertGetId([
            'log_name' => $logName,
            'description' => 'test',
            'subject_type' => $subjectType,
            'subject_id' => $subjectType ? '00000000-0000-4000-8000-000000000001' : null,
            'properties' => '{}',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
