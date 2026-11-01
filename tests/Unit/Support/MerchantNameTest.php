<?php

namespace Tests\Unit\Support;

use App\Support\MerchantName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MerchantNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'capitals' => ['AMERICAN EXPRESS', 'American Express'],
            'apostrophe' => ["SAINSBURY'S S/MKTS", "Sainsbury's S/Mkts"],
            'initials' => ['TFL TRAVEL CH', 'TFL Travel CH'],
            'hyphen' => ['MARKS-AND-SPENCER', 'Marks-And-Spencer'],
            'already cased' => ['eBay', 'eBay'],
            'mixed' => ["McDonald's", "McDonald's"],
            'digits only' => ['1234', '1234'],
            'spacing kept' => ['PRET  A MANGER', 'Pret  A Manger'],
        ];
    }

    #[Test]
    #[DataProvider('names')]
    public function it_title_cases_names_sent_in_capitals(string $raw, string $expected): void
    {
        $this->assertSame($expected, MerchantName::display($raw));
    }
}
