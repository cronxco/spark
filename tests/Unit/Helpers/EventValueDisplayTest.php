<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventValueDisplayTest extends TestCase
{
    #[Test]
    public function ratio_units_attach_to_the_value(): void
    {
        $this->assertSame('5/5', format_event_value_display(5, '/5'));
    }

    #[Test]
    public function percent_attaches_to_the_value(): void
    {
        $this->assertSame('80%', format_event_value_display(80, '%'));
    }

    #[Test]
    public function other_units_are_separated_by_a_space(): void
    {
        $this->assertSame('12 km', format_event_value_display(12, 'km'));
    }

    #[Test]
    public function missing_unit_returns_the_bare_value(): void
    {
        $this->assertSame('12', format_event_value_display(12, null));
    }
}
