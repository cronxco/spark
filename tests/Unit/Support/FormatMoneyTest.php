<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FormatMoneyTest extends TestCase
{
    #[Test]
    public function known_currencies_use_their_symbol(): void
    {
        $this->assertSame('£24.80', format_money(24.8, 'GBP'));
        $this->assertSame('$3.00', format_money(3, 'usd'));
        $this->assertSame('€1,250.00', format_money(1250, 'EUR'));
    }

    #[Test]
    public function other_currencies_use_their_code(): void
    {
        $this->assertSame('CHF 12.50', format_money(12.5, 'CHF'));
    }

    #[Test]
    public function a_missing_currency_falls_back_to_pounds(): void
    {
        $this->assertSame('£5.00', format_money(5));
        $this->assertSame('£5.00', format_money(5, ''));
    }

    #[Test]
    public function decimals_can_be_dropped(): void
    {
        $this->assertSame('¥1,200', format_money(1200, 'JPY', 0));
    }
}
