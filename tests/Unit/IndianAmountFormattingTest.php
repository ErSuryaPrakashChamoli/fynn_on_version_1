<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every rupee figure in the Daily Commitment module is shown in Indian
 * digit grouping with its word statement underneath, so a number can be
 * read back without counting commas.
 */
class IndianAmountFormattingTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function amounts(): array
    {
        return [
            'zero' => [0, '₹0', 'Zero'],
            'under a thousand' => [999, '₹999', 'Nine Hundred Ninety Nine'],
            'thousands' => [45000, '₹45,000', 'Forty Five Thousand'],
            'lakhs' => [400000, '₹4,00,000', 'Four Lakh'],
            'lakhs and thousands' => [1250000, '₹12,50,000', 'Twelve Lakh Fifty Thousand'],
            'a crore' => [10000000, '₹1,00,00,000', 'One Crore'],
            'every group at once' => [
                123456789,
                '₹12,34,56,789',
                'Twelve Crore Thirty Four Lakh Fifty Six Thousand Seven Hundred Eighty Nine',
            ],
            'a teen in the tail' => [1000017, '₹10,00,017', 'Ten Lakh Seventeen'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_an_amount_reads_the_same_in_digits_and_in_words(int $value, string $digits, string $words): void
    {
        $this->assertSame($digits, indianAmount($value));
        $this->assertSame($words, indianAmountInWords($value));
    }

    public function test_paise_are_dropped_rather_than_shown(): void
    {
        $this->assertSame('₹12,50,000', indianAmount(1249999.6));
        $this->assertSame('Twelve Lakh Fifty Thousand', indianAmountInWords(1249999.6));
    }

    public function test_a_negative_figure_keeps_its_sign_outside_the_symbol(): void
    {
        $this->assertSame('-₹1,500', indianAmount(-1500));
        $this->assertSame('Minus One Thousand Five Hundred', indianAmountInWords(-1500));
    }

    public function test_nothing_at_all_formats_to_nothing(): void
    {
        $this->assertSame('', indianAmount(null));
        $this->assertSame('', indianAmountInWords(''));
    }

    public function test_a_figure_beyond_ninety_nine_crore_still_groups_correctly(): void
    {
        $this->assertSame('₹1,20,00,00,000', indianAmount(1200000000));
        $this->assertSame('One Hundred Twenty Crore', indianAmountInWords(1200000000));
    }
}
