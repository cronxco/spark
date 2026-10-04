<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Receipt\ReceiptTimeResolver;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReceiptTimeResolverTest extends TestCase
{
    public static function receiptTimes(): array
    {
        return [
            'reported M&S summer time' => [['transaction_date' => '2026-10-04T14:50:14'], 'Europe/London', '2026-10-04T13:50:14+00:00'],
            'winter time' => [['transaction_date' => '2026-12-04T14:50:14'], 'Europe/London', '2026-12-04T14:50:14+00:00'],
            'explicit offset' => [['transaction_date' => '2026-10-04T14:50:14+01:00'], 'America/New_York', '2026-10-04T13:50:14+00:00'],
            'explicit UTC' => [['transaction_date' => '2026-10-04T13:50:14Z'], 'Europe/London', '2026-10-04T13:50:14+00:00'],
            'merchant overrides user' => [['transaction_date' => '2026-10-04T14:50:14', 'transaction_timezone' => 'Australia/Sydney'], 'Europe/London', '2026-10-04T03:50:14+00:00'],
            'date only' => [['transaction_date' => '2026-10-04'], 'Europe/London', '2026-10-03T23:00:00+00:00'],
            'before spring DST' => [['transaction_date' => '2026-03-29T00:30:00'], 'Europe/London', '2026-03-29T00:30:00+00:00'],
            'after spring DST' => [['transaction_date' => '2026-03-29T02:30:00'], 'Europe/London', '2026-03-29T01:30:00+00:00'],
        ];
    }

    public static function uncertainTimes(): array
    {
        return [
            'invented UTC suffix causes future event' => [['transaction_date' => '2026-10-04T14:50:14Z'], 'future_date'],
            'delivery date' => [['transaction_date' => '2026-10-10T14:50:14'], 'future_date'],
            'missing date' => [[], 'missing_or_invalid_date'],
            'null date' => [['transaction_date' => null], 'missing_or_invalid_date'],
            'relative date' => [['transaction_date' => 'tomorrow'], 'missing_or_invalid_date'],
            'invalid calendar date' => [['transaction_date' => '2026-02-30T14:50:14'], 'invalid_date'],
            'invalid timezone' => [['transaction_date' => '2026-10-04T14:50:14', 'transaction_timezone' => 'nonsense'], 'invalid_timezone'],
            'autumn DST overlap' => [['transaction_date' => '2026-10-25T01:30:00'], 'ambiguous_local_time'],
            'spring DST gap' => [['transaction_date' => '2026-03-29T01:30:00'], 'nonexistent_local_time'],
        ];
    }

    public static function invalidEmailDates(): array
    {
        return [['invalid header'], ['2027-10-04T13:50:55Z']];
    }

    #[Test]
    #[DataProvider('receiptTimes')]
    public function it_converts_receipt_times_to_utc(array $transaction, string $timezone, string $expected): void
    {
        $result = (new ReceiptTimeResolver)->resolve(
            $transaction,
            'Sun, 04 Oct 2026 14:50:30 +0100',
            $timezone,
            Carbon::parse('2026-12-31T23:00:00Z'),
        );

        $this->assertSame($expected, $result['time']->toIso8601String());
        $this->assertSame('receipt', $result['metadata']['source']);
        $this->assertArrayNotHasKey('needs_review', $result['metadata']);
    }

    #[Test]
    #[DataProvider('uncertainTimes')]
    public function it_retains_uncertain_extractions_and_falls_back_to_the_email(array $transaction, string $reason): void
    {
        $result = (new ReceiptTimeResolver)->resolve(
            $transaction,
            'Sun, 04 Oct 2026 14:50:30 +0100',
            'Europe/London',
            Carbon::parse('2026-10-04T13:50:55Z'),
        );

        $this->assertSame('2026-10-04T13:50:30+00:00', $result['time']->toIso8601String());
        $this->assertTrue($result['metadata']['needs_review']);
        $this->assertSame($reason, $result['metadata']['reason']);
        $this->assertSame($transaction['transaction_date'] ?? null, $result['metadata']['original_date']);
    }

    #[Test]
    #[DataProvider('invalidEmailDates')]
    public function the_fallback_cannot_be_in_the_future(string $emailDate): void
    {
        $result = (new ReceiptTimeResolver)->resolve([], $emailDate, 'Europe/London', Carbon::parse('2026-10-04T13:50:55Z'));

        $this->assertSame('2026-10-04T13:50:55+00:00', $result['time']->toIso8601String());
    }
}
