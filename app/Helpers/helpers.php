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
        return $rest . ',' . $lastThree;
    }

    return $lastThree;
}





