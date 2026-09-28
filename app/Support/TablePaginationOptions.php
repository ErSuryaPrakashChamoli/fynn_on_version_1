<?php

namespace App\Support;

/**
 * The single "records per page" list every table in the panel offers.
 *
 * Applied panel-wide as the Table default in
 * AdminPanelProvider::configurePaginationOptions(), and read by the
 * duplicated top-of-toolbar selector
 * (resources/views/filament/components/table-records-per-page-top.blade.php)
 * so both selectors always carry exactly the same options — a table's
 * current value that one selector lacks as an option is what used to make
 * the top one silently show "5" while the bottom showed "All".
 */
final class TablePaginationOptions
{
    /**
     * @var array<int, int|'all'>
     */
    public const array OPTIONS = [5, 10, 50, 100, 'all'];
}
