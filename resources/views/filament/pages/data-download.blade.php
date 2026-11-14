<x-filament-panels::page>
    @php($authenticator = $this->authenticator())

    @if ($authenticator === null)
        <x-filament::section
            icon="heroicon-o-shield-exclamation"
            icon-color="warning"
            heading="Owner approval is not set up yet"
            description="Leads and customers downloads stay switched off until the owner sets up an authenticator app."
        >
            <ol class="mb-5 space-y-3 text-sm text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-3">
                    <span class="fynn-avatar fynn-avatar--sm" aria-hidden="true">1</span>
                    <span class="pt-1">The owner installs Google Authenticator or Microsoft Authenticator on their phone.</span>
                </li>
                <li class="flex items-start gap-3">
                    <span class="fynn-avatar fynn-avatar--sm" aria-hidden="true">2</span>
                    <span class="pt-1">Click "Set up owner authenticator" and the owner scans the QR code. This is done once.</span>
                </li>
                <li class="flex items-start gap-3">
                    <span class="fynn-avatar fynn-avatar--sm" aria-hidden="true">3</span>
                    <span class="pt-1">For every download, the owner reads out the 6-digit code the app shows, and you enter it.</span>
                </li>
            </ol>

            {{ $this->setUpAuthenticatorAction }}
        </x-filament::section>
    @else
        <x-filament::section
            icon="heroicon-o-shield-check"
            icon-color="success"
            heading="Every download needs the owner's code"
            :description="'Ask '.$authenticator->owner_name.' for the 6-digit code in their authenticator app. Each code approves one download.'"
        >
            <dl class="fynn-dl">
                <div class="fynn-dl-item">
                    <dt>Owner</dt>
                    <dd>{{ $authenticator->owner_name }}</dd>
                </div>
                <div class="fynn-dl-item">
                    <dt>Set up</dt>
                    <dd>
                        {{ $authenticator->configured_at?->format('d M Y, h:i A') ?? '—' }}
                        @if ($authenticator->configuredBy)
                            <span class="font-normal text-gray-500 dark:text-gray-400">by {{ $authenticator->configuredBy->name }}</span>
                        @endif
                    </dd>
                </div>
                <div class="fynn-dl-item">
                    <dt>Last approval</dt>
                    <dd>{{ $authenticator->last_used_at?->diffForHumans() ?? 'Not used yet' }}</dd>
                </div>
            </dl>

            <div class="mt-4">
                {{ $this->replaceAuthenticatorAction }}
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Choose the dates" description="Records are picked by the date they were added. Clear a date to leave that side open." icon="heroicon-o-calendar-days">
        {{ $this->form }}
    </x-filament::section>

    <div class="grid gap-6 md:grid-cols-2">
        <x-filament::section icon="heroicon-o-funnel" :heading="'Leads · '.number_format($this->leadCount())" :description="'Added '.$this->rangeLabel().', with every lead field.'">
            {{ $this->downloadLeadsAction }}
        </x-filament::section>

        <x-filament::section icon="heroicon-o-user-circle" :heading="'Customers · '.number_format($this->customerCount())" :description="'Added '.$this->rangeLabel().', with every customer field.'">
            {{ $this->downloadCustomersAction }}
        </x-filament::section>
    </div>

    <p class="fynn-note mt-0">
        <x-filament::icon icon="heroicon-m-information-circle" />
        <span>The file is prepared in the background. When it is ready, a notification with the CSV and Excel links appears under the bell.</span>
    </p>

    @php($recentActivity = $this->recentActivity())

    <x-filament::section heading="Recent activity" description="Approved downloads and changes to the owner authenticator." icon="heroicon-o-clock">
        @if ($recentActivity->isEmpty())
            <div class="fynn-empty fynn-empty--compact">
                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-clock" /></span>
                <span>Nothing yet.</span>
            </div>
        @else
            <ul class="fynn-list text-sm">
                @foreach ($recentActivity as $activity)
                    <li class="fynn-list-row flex-wrap">
                        <div class="fynn-list-main">
                            <span class="fynn-avatar fynn-avatar--sm">{{ \Illuminate\Support\Str::of($activity->causer?->name ?? 'System')->explode(' ')->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}</span>
                            <div class="min-w-0">
                                <span class="font-medium text-gray-950 dark:text-white">{{ $activity->causer?->name ?? 'System' }}</span>
                                <span class="text-gray-600 dark:text-gray-300">· {{ $activity->description }}</span>
                                @if ($activity->getProperty('dataset'))
                                    <span class="text-gray-500 dark:text-gray-400">
                                        · {{ $activity->getProperty('from') || $activity->getProperty('until')
                                            ? ($activity->getProperty('from') ?? 'start').' to '.($activity->getProperty('until') ?? 'now')
                                            : ($activity->getProperty('source') === 'selected records' ? 'selected records' : 'current list filters') }}
                                        · {{ count($activity->getProperty('columns') ?? []) }} fields
                                    </span>
                                @endif
                            </div>
                        </div>
                        <span class="fynn-list-meta whitespace-nowrap">{{ $activity->created_at?->format('d M Y, h:i A') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
