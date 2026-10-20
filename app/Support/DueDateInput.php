<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Reads a due date typed or picked in a follow-up outcome filter: Y-m-d,
 * or Y-m-d H:i:s as Filament's non-native DatePicker holds its state. Only
 * the day is kept.
 */
class DueDateInput
{
    /**
     * Null for a blank, malformed or impossible date. A browser date box
     * also reports the year while it is still being typed ("0002-10-01");
     * a year outside 2000–2100 is read as blank rather than as a range
     * reaching back two thousand years.
     */
    public static function parse(?string $date): ?Carbon
    {
        if (blank($date)
            || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[1] < 2000 || (int) $parts[1] > 2100) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d', substr($date, 0, 10));
    }
}
