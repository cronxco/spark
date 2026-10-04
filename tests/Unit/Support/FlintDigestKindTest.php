<?php

namespace Tests\Unit\Support;

use App\Models\Block;
use App\Models\Event;
use App\Support\FlintDigestKind;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintDigestKindTest extends TestCase
{
    #[Test]
    public function prefers_the_kind_recorded_at_write_time(): void
    {
        $event = $this->digest(['kind' => 'reading_list', 'title' => 'Morning Digest']);

        $this->assertSame(FlintDigestKind::READING_LIST, FlintDigestKind::for($event));
    }

    /**
     * The layout must not depend on how a digest happened to be named. The
     * server knows the routine from a verified run token; the title is prose.
     */
    #[Test]
    public function falls_back_to_the_routine_before_the_title(): void
    {
        $event = $this->digest(['routine' => 'news_roundup', 'title' => 'Anything At All']);

        $this->assertSame(FlintDigestKind::NEWS_ROUNDUP, FlintDigestKind::for($event));
    }

    #[Test]
    public function the_topics_routine_writes_no_digest_so_has_no_kind(): void
    {
        $event = $this->digest(['routine' => 'topics', 'title' => 'Morning Digest']);

        $this->assertSame(FlintDigestKind::BRIEFING, FlintDigestKind::for($event));
    }

    #[Test]
    public function still_reads_the_title_for_digests_written_before_kind_existed(): void
    {
        $this->assertSame(
            FlintDigestKind::READING_LIST,
            FlintDigestKind::for($this->digest(['title' => 'Reading list — Friday']))
        );
        $this->assertSame(
            FlintDigestKind::NEWS_ROUNDUP,
            FlintDigestKind::for($this->digest(['title' => 'News roundup — Friday']))
        );
        $this->assertSame(
            FlintDigestKind::BRIEFING,
            FlintDigestKind::for($this->digest(['title' => 'Evening Digest — Fri 11 Sep']))
        );
    }

    /**
     * The back catalogue carries flint_story and flint_news_roundup_story from
     * before the type was pinned down, so block sniffing stays a last resort.
     */
    #[Test]
    public function falls_back_to_block_types_when_the_title_says_nothing(): void
    {
        $event = $this->digest(['title' => 'Saturday']);
        $event->setRelation('blocks', new Collection([
            new Block(['block_type' => 'flint_news', 'title' => 'A story']),
            new Block(['block_type' => 'flint_editorial_note', 'title' => 'Run notes']),
        ]));

        $this->assertSame(FlintDigestKind::NEWS_ROUNDUP, FlintDigestKind::for($event));
    }

    /**
     * Before the type was pinned down the same story block was written as
     * flint_story and then flint_news_roundup_story within one week. The
     * fallback exists for that material, so it has to recognise it.
     */
    #[Test]
    public function recognises_the_legacy_news_block_types(): void
    {
        foreach (['flint_story', 'flint_news_roundup_story'] as $legacyType) {
            $event = $this->digest(['title' => 'Saturday']);
            $event->setRelation('blocks', new Collection([
                new Block(['block_type' => $legacyType, 'title' => 'A story']),
                new Block(['block_type' => 'flint_editorial_note', 'title' => 'Run notes']),
            ]));

            $this->assertSame(
                FlintDigestKind::NEWS_ROUNDUP,
                FlintDigestKind::for($event),
                "expected {$legacyType} to classify as a news roundup",
            );
        }
    }

    #[Test]
    public function reading_blocks_identify_a_reading_list(): void
    {
        $event = $this->digest(['title' => 'Saturday']);
        $event->setRelation('blocks', new Collection([
            new Block(['block_type' => 'flint_reading_pick', 'title' => 'A piece']),
            new Block(['block_type' => 'flint_reading_drop', 'title' => 'An old piece']),
        ]));

        $this->assertSame(FlintDigestKind::READING_LIST, FlintDigestKind::for($event));
    }

    /**
     * A briefing carries a day-context block, which must not be mistaken for
     * story content and tip the digest into another layout.
     */
    #[Test]
    public function day_context_and_questions_do_not_count_as_content(): void
    {
        $event = $this->digest(['title' => 'Saturday']);
        $event->setRelation('blocks', new Collection([
            new Block(['block_type' => 'flint_day_context', 'title' => 'Today at a glance']),
            new Block(['block_type' => 'flint_user_question', 'title' => 'A question']),
        ]));

        $this->assertSame(FlintDigestKind::BRIEFING, FlintDigestKind::for($event));
    }

    /** @param array<string, mixed> $meta */
    private function digest(array $meta): Event
    {
        $event = new Event(['service' => 'flint', 'action' => 'had_summary']);
        $event->event_metadata = $meta;
        $event->setRelation('blocks', new Collection);

        return $event;
    }
}
