@php
    /** @var \Filament\Actions\Action $downloadAction */
@endphp

<ol class="space-y-4 text-sm text-gray-600 dark:text-gray-300">
    <li class="flex items-start gap-3">
        <span class="fynn-avatar fynn-avatar--sm" aria-hidden="true">1</span>

        <div class="min-w-0 flex-1 pt-1">
            <p>Download the current details of every employee, active and inactive.</p>

            <div class="mt-2">
                {{ $downloadAction }}
            </div>
        </div>
    </li>

    <li class="flex items-start gap-3">
        <span class="fynn-avatar fynn-avatar--sm" aria-hidden="true">2</span>

        <div class="min-w-0 flex-1 space-y-2 pt-1">
            <p>
                Edit only the cells you want to change. Rows are matched by
                <span class="fynn-chip font-mono">emp_id</span>,
                so leave that column as it is. Blank cells keep the current value,
                and rows you delete are left unchanged.
            </p>

            <p>
                To mark someone inactive, set
                <span class="fynn-chip font-mono">status</span>
                to Inactive and fill
                <span class="fynn-chip font-mono">inactive_date</span>.
                This is refused while team members, open customers or leads are
                still assigned to them. Move those first from their Edit page. To
                bring someone back, set status to Active and clear the date.
            </p>

            <p>
                To give someone a new boss, change their
                <span class="fynn-chip font-mono">reports_to</span>
                to the new boss's emp_id, then choose below whether they move alone
                or with their team.
            </p>
        </div>
    </li>

    <li class="flex items-start gap-3">
        <span class="fynn-avatar fynn-avatar--sm" aria-hidden="true">3</span>

        <div class="min-w-0 flex-1 pt-1">Upload the edited file below.</div>
    </li>
</ol>
