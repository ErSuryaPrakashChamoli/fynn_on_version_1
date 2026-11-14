<x-filament-panels::page>

    <x-filament::section>

        <dl class="fynn-dl">

            <div class="fynn-dl-item">
                <dt>Employee Name</dt>
                <dd class="text-base font-bold">{{ $this->record->emp_name }}</dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Employee ID</dt>
                <dd>{{ $this->record->emp_id }}</dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Designation</dt>
                <dd>
                    {{ \App\Models\Employee::designationOptions()[$this->record->designation] ?? '-' }}
                </dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Date of Joining</dt>
                <dd>{{ optional($this->record->doj)->format('d M Y') ?? '-' }}</dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Reporting Date</dt>
                <dd>{{ optional($this->record->reporting_date)->format('d M Y') ?? '-' }}</dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Mobile</dt>
                <dd>{{ $this->record->mobile }}</dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Email</dt>
                <dd>{{ $this->record->email }}</dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Exit Status</dt>
                <dd>{{ ucfirst($this->record->exit_status ?? 'No') }}</dd>
            </div>

        </dl>

    </x-filament::section>

    <x-filament::section>

        <dl class="fynn-dl">

            <div class="fynn-dl-item">
                <dt>Business Head</dt>
                <dd>{{ $this->record->businessHead?->emp_name ?? '-' }}</dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Cluster Manager</dt>
                <dd>{{ $this->record->cluster?->emp_name ?? '-' }}</dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Manager</dt>
                <dd>{{ $this->record->manager?->emp_name ?? '-' }}</dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Team Leader</dt>
                <dd>{{ $this->record->superviser?->emp_name ?? '-' }}</dd>
            </div>

            <div class="fynn-dl-item">
                <dt>Team Size</dt>
                <dd>{{ \App\Support\HierarchyHelper::children($this->record)->count() }}</dd>
            </div>

        </dl>

    </x-filament::section>

    <div class="fynn-kpi-grid">

        <div class="fynn-kpi" style="--fynn-kpi-accent: #3b82f6">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-flag" /></span>
                <div class="fynn-kpi-label">Target</div>
            </div>
            <div class="fynn-kpi-value">
                {{ indianCurrencyFormat($this->performance['target'] ?? 0) }}
            </div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #22c55e">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-arrow-trending-up" /></span>
                <div class="fynn-kpi-label">Actual</div>
            </div>
            <div class="fynn-kpi-value text-success-600 dark:text-success-400">
                {{ indianCurrencyFormat($this->performance['actual'] ?? 0) }}
            </div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #ef4444">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-receipt-refund" /></span>
                <div class="fynn-kpi-label">Cashback</div>
            </div>
            <div class="fynn-kpi-value">
                {{ indianCurrencyFormat($this->performance['cashback'] ?? 0) }}
            </div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #ef4444">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-receipt-percent" /></span>
                <div class="fynn-kpi-label">Subvention</div>
            </div>
            <div class="fynn-kpi-value">
                {{ indianCurrencyFormat($this->performance['subvention'] ?? 0) }}
            </div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #ef4444">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-minus-circle" /></span>
                <div class="fynn-kpi-label">Docking</div>
            </div>
            <div class="fynn-kpi-value">
                {{ indianCurrencyFormat($this->performance['docking'] ?? 0) }}
            </div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #0d9488">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-banknotes" /></span>
                <div class="fynn-kpi-label">Count Achievement</div>
            </div>
            <div class="fynn-kpi-value">
                {{ indianCurrencyFormat($this->performance['count_achievement'] ?? 0) }}
            </div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #8b5cf6">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-chart-pie" /></span>
                <div class="fynn-kpi-label">Achievement %</div>
            </div>
            <div class="fynn-kpi-value">
                {{ number_format($this->performance['percentage'] ?? 0, 2) }}%
            </div>
        </div>

        <div class="fynn-kpi" style="--fynn-kpi-accent: #f59e0b">
            <div class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-gift" /></span>
                <div class="fynn-kpi-label">Incentive</div>
            </div>
            <div class="fynn-kpi-value text-primary-800 dark:text-primary-300">
                {{ indianCurrencyFormat($this->performance['incentive'] ?? 0) }}
            </div>
        </div>

    </div>

    <div>
        {{ $this->table }}
    </div>

</x-filament-panels::page>
