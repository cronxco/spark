<?php

namespace Tests\Unit\Services\Flint\Ask;

use App\Services\Flint\Ask\AskFlintFollowUps;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Flint's suggested next questions are lifted out of the answer, and never
 * reach the reader as text, even while the answer streams.
 */
class AskFlintFollowUpsTest extends TestCase
{
    #[Test]
    public function the_block_is_lifted_out_of_the_answer(): void
    {
        $split = AskFlintFollowUps::split("A light week.\n\n<follow-ups>\n- What about next week?\nAnd the weekend?\nA third one\n</follow-ups>");

        $this->assertSame('A light week.', $split['text']);
        $this->assertSame(['What about next week?', 'And the weekend?'], $split['follow_ups']);
    }

    #[Test]
    public function an_answer_without_the_block_is_unchanged(): void
    {
        $this->assertSame(['text' => 'Done.', 'follow_ups' => []], AskFlintFollowUps::split('Done.'));
    }

    #[Test]
    public function an_unclosed_block_still_comes_out(): void
    {
        $split = AskFlintFollowUps::split("Done.\n<follow-ups>\nWhat next?");

        $this->assertSame('Done.', $split['text']);
        $this->assertSame(['What next?'], $split['follow_ups']);
    }

    #[Test]
    public function streaming_holds_back_only_what_could_be_the_tag(): void
    {
        $this->assertSame(5, AskFlintFollowUps::sendable('Hello'));
        $this->assertSame(6, AskFlintFollowUps::sendable('Hello <foll'));
        $this->assertSame(7, AskFlintFollowUps::sendable("Hello\n\n<follow-ups>\nWhat"));
        $this->assertSame(5, AskFlintFollowUps::sendable('a < b'));
    }
}
