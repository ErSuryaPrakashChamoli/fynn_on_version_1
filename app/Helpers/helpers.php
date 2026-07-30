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


 function getEmployeeIds(): Collection
{
    $employee = auth()->user()->employee;

    if (auth()->user()->hasRole('Admin')) {
        return Employee::pluck('id');
    }

    if ($employee->designation === 'Manager') {
        return Employee::where('manager_id', $employee->id)->pluck('id');
    }

    if ($employee->designation === 'Team Leader') {
        return Employee::where('superviser_id', $employee->id)->pluck('id');
    }

    return collect([$employee->id]);
}
