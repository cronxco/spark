<?php

namespace Tests\Unit\Services\Flint;

use App\Models\User;
use App\Services\FlintDigestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The digest EventObject is what the app groups a digest under, so two digests
 * sharing one object render as two sections of the same briefing.
 */
class DigestObjectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_day_briefing_keeps_the_period_keyed_object(): void
    {
        $user = User::factory()->create();
        $date = Carbon::parse('2026-09-02');

        $object = app(FlintDigestService::class)->resolveDigestObject($user, 'morning', $date);

        $this->assertSame('morning_digest', $object->type);
        $this->assertSame('2026-09-02 AM', $object->title);
    }

    #[Test]
    public function an_explicit_digest_routine_is_treated_as_the_day_briefing(): void
    {
        $user = User::factory()->create();
        $date = Carbon::parse('2026-09-02');
        $service = app(FlintDigestService::class);

        $this->assertTrue($service->resolveDigestObject($user, 'evening', $date, 'digest')
            ->is($service->resolveDigestObject($user, 'evening', $date)));
    }

    /**
     * The once-daily routines each declare a fixed period, so before this they
     * collapsed onto the day briefing's object: the news roundup always landed
     * inside the morning digest, which already carries a news section.
     */
    #[Test]
    public function a_once_daily_routine_gets_its_own_object(): void
    {
        $user = User::factory()->create();
        $date = Carbon::parse('2026-09-02');
        $service = app(FlintDigestService::class);

        $briefing = $service->resolveDigestObject($user, 'morning', $date);
        $roundup = $service->resolveDigestObject($user, 'morning', $date, 'news_roundup');

        $this->assertFalse($roundup->is($briefing));
        $this->assertSame('news_roundup_digest', $roundup->type);
        $this->assertSame('2026-09-02 NEWS ROUNDUP', $roundup->title);
        $this->assertSame('news_roundup', $roundup->metadata['routine']);
        $this->assertSame('morning', $roundup->metadata['period']);
    }

    #[Test]
    public function two_evening_routines_do_not_share_an_object(): void
    {
        $user = User::factory()->create();
        $date = Carbon::parse('2026-09-02');
        $service = app(FlintDigestService::class);

        $reading = $service->resolveDigestObject($user, 'evening', $date, 'reading_list');
        $topics = $service->resolveDigestObject($user, 'evening', $date, 'topics');

        $this->assertFalse($reading->is($topics));
        $this->assertFalse($reading->is($service->resolveDigestObject($user, 'evening', $date)));
        $this->assertSame('2026-09-02 READING LIST', $reading->title);
    }

    #[Test]
    public function the_same_routine_on_the_same_day_reuses_its_object(): void
    {
        $user = User::factory()->create();
        $date = Carbon::parse('2026-09-02');
        $service = app(FlintDigestService::class);

        $this->assertTrue(
            $service->resolveDigestObject($user, 'morning', $date, 'news_roundup')
                ->is($service->resolveDigestObject($user, 'morning', $date, 'news_roundup')),
        );
    }
}
