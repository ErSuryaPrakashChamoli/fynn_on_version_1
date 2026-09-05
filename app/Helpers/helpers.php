<?php

function indianCurrencyFormat($number): string
{
    if ($number === null || $number === '') {
        return '';
    }

    $number = (string) ((int) $number);

    $lastThree = substr($number, -3);
    $rest = substr($number, 0, -3);

    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);

        return $rest.','.$lastThree;
    }

    return $lastThree;
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
