<?php

namespace App\Enums;

/**
 * The kind of Excel-style filter a listing column offers from its header
 * (see App\Support\ColumnHeaderFilters\ColumnHeaderFilter::for()).
 */
enum ColumnHeaderFilterKind: string
{
    /** A checklist of the distinct values the column holds, with a search box. */
    case Values = 'values';

    /** A from / to date range, for date and date-time columns. */
    case DateRange = 'date';

    /** A checklist of the options of the table's select filter of the same name. */
    case Options = 'options';

    /** A "contains" box, for computed columns that are searchable. */
    case Text = 'text';

    public function usesChecklist(): bool
    {
        return in_array($this, [self::Values, self::Options], true);
    }
}
