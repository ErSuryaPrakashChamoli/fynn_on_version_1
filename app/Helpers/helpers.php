<?php

function indianCurrencyFormat($number): string
{
    if ($number === null || $number === '') {
        return '';
    }

    $sign = (int) $number < 0 ? '-' : '';
    $number = (string) abs((int) $number);

    $lastThree = substr($number, -3);
    $rest = substr($number, 0, -3);

    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);

        return $sign.$rest.','.$lastThree;
    }

    return $sign.$lastThree;
}

/**
 * Compact Indian short form for headline figures — 1,25,00,000 becomes
 * "₹1.25 Cr", 1,000,000 becomes "₹10 L". Full precision stays with
 * indianCurrencyFormat(); this is for KPI cards where the exact rupee
 * would be noise.
 */
function shortIndianAmount($number, string $symbol = '₹'): string
{
    $number = (float) $number;

    if ($number == 0.0) {
        return $symbol.'0';
    }

    $sign = $number < 0 ? '-' : '';
    $number = abs($number);

    [$value, $suffix] = match (true) {
        $number >= 10000000 => [$number / 10000000, ' Cr'],
        $number >= 100000 => [$number / 100000, ' L'],
        $number >= 1000 => [$number / 1000, ' K'],
        default => [$number, ''],
    };

    $formatted = $value >= 100 || fmod($value, 1) == 0.0
        ? number_format($value, 0)
        : rtrim(rtrim(number_format($value, 2), '0'), '.');

    return $sign.$symbol.$formatted.$suffix;
}

/**
 * A rupee figure in Indian digit grouping with the symbol in front —
 * 1250000 becomes "₹12,50,000". Paise are dropped: every amount in the
 * Daily Commitment module is a whole-rupee loan figure.
 */
function indianAmount($number, string $symbol = '₹'): string
{
    if ($number === null || $number === '') {
        return '';
    }

    $rupees = (int) round((float) $number);

    return ($rupees < 0 ? '-' : '').$symbol.indianCurrencyFormat(abs($rupees));
}

/**
 * The word statement of a rupee figure, in the Indian system —
 * 1250000 becomes "Twelve Lakh Fifty Thousand". Shown under the digits
 * so a number can be read back without counting commas.
 */
function indianAmountInWords($number): string
{
    if ($number === null || $number === '') {
        return '';
    }

    $rupees = (int) round((float) $number);

    if ($rupees < 0) {
        return 'Minus '.indianAmountInWords(-$rupees);
    }

    if ($rupees === 0) {
        return 'Zero';
    }

    $ones = [
        1 => 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    $tens = [2 => 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $upToNinetyNine = function (int $value) use ($ones, $tens): string {
        if ($value < 20) {
            return $ones[$value] ?? '';
        }

        $word = $tens[intdiv($value, 10)];

        return $value % 10 ? $word.' '.$ones[$value % 10] : $word;
    };

    $crore = intdiv($rupees, 10000000);
    $lakh = intdiv($rupees, 100000) % 100;
    $thousand = intdiv($rupees, 1000) % 100;
    $hundred = intdiv($rupees, 100) % 10;
    $rest = $rupees % 100;

    $words = [];

    if ($crore > 0) {
        // Beyond 99 crore the count itself needs the Indian grouping
        // again — 12,34,00,00,000 reads "Twelve Thousand Thirty Four Crore".
        $words[] = ($crore > 99 ? indianAmountInWords($crore) : $upToNinetyNine($crore)).' Crore';
    }

    if ($lakh > 0) {
        $words[] = $upToNinetyNine($lakh).' Lakh';
    }

    if ($thousand > 0) {
        $words[] = $upToNinetyNine($thousand).' Thousand';
    }

    if ($hundred > 0) {
        $words[] = $ones[$hundred].' Hundred';
    }

    if ($rest > 0) {
        $words[] = $upToNinetyNine($rest);
    }

    return implode(' ', $words);
}
