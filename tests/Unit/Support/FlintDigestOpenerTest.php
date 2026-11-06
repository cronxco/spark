<?php

namespace Tests\Unit\Support;

use App\Support\FlintDigestOpener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FlintDigestOpenerTest extends TestCase
{
    #[Test]
    public function short_paragraphs_in_capitals_are_section_headings(): void
    {
        $this->assertTrue(FlintDigestOpener::isSectionHeading('DRIVING THE DAY'));
        $this->assertTrue(FlintDigestOpener::isSectionHeading("THE NOVEMBER TRANSFER IS DONE \u{2014}"));
        $this->assertTrue(FlintDigestOpener::isSectionHeading("WHAT I\u{2019}VE BEEN READING FOR YOU \u{2014}"));
    }

    #[Test]
    public function prose_numbers_and_long_capitals_are_not_section_headings(): void
    {
        $this->assertFalse(FlintDigestOpener::isSectionHeading('Good Friday morning.'));
        $this->assertFalse(FlintDigestOpener::isSectionHeading('£1,500'));
        $this->assertFalse(FlintDigestOpener::isSectionHeading(''));
        $this->assertFalse(FlintDigestOpener::isSectionHeading(str_repeat('LOUD ', 20)));
    }

    #[Test]
    public function a_heading_keeps_its_capitals_and_loses_its_trailing_dash(): void
    {
        $this->assertSame('THE NOVEMBER TRANSFER IS DONE', FlintDigestOpener::headingText("THE NOVEMBER TRANSFER IS DONE \u{2014}"));
        $this->assertSame('DRIVING THE DAY', FlintDigestOpener::headingText('**DRIVING THE DAY**'));
        $this->assertSame('COMING UP', FlintDigestOpener::headingText('## COMING UP'));
    }
}
